import { createHash, timingSafeEqual } from 'node:crypto';
import { createServer, type IncomingMessage, type ServerResponse } from 'node:http';
import { Ajv, type ValidateFunction } from 'ajv';
import { ADObjectStore } from './ad-object-store.js';
import { CacheLifecycle } from './cache.js';
import { TIEClient, TIEUpstreamError } from './client.js';
import { loadConfig } from './config.js';
import { configureStore, customTools, setSharedStore } from './custom-tools.js';
import { dispatchTool } from './dispatch.js';
import { liveFreshness } from './freshness.js';
import { tools as generatedTools, type ToolInputSchema } from './generated/tools.js';

type Args = Record<string, unknown>;

interface Tool {
  name: string;
  description: string;
  inputSchema: ToolInputSchema;
  safety: 'read' | 'write' | 'destructive';
  usesSnapshot: boolean;
  invoke: (args: Args) => Promise<unknown>;
}

class RequestError extends Error {
  constructor(readonly statusCode: number, message: string) {
    super(message);
  }
}

const MAX_BODY_BYTES = 1024 * 1024;
const RETRY_SECONDS = '3';

function digest(token: string): Buffer {
  return createHash('sha256').update(token).digest();
}

function send(response: ServerResponse, code: number, body: unknown): void {
  const json = JSON.stringify(body ?? null);
  response.writeHead(code, {
    'Content-Type': 'application/json; charset=utf-8',
    'Cache-Control': 'no-store',
  });
  response.end(json);
}

async function readArgs(request: IncomingMessage): Promise<Args> {
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const chunk of request.iterator({ destroyOnReturn: false }) as AsyncIterable<Buffer>) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) {
      request.resume();
      throw new RequestError(413, 'JSON body exceeds 1 MiB');
    }
    chunks.push(chunk);
  }

  let args: unknown;
  try {
    args = size ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {};
  } catch {
    throw new RequestError(400, 'Invalid JSON body');
  }
  if (!args || typeof args !== 'object' || Array.isArray(args)) {
    throw new RequestError(400, 'The JSON body must be an object');
  }
  return args as Args;
}

function registerTools(client: TIEClient, cache: CacheLifecycle, enabledNames: string[]) {
  const ajv = new Ajv({ strict: false, validateFormats: false, ownProperties: true });
  const generated = new Map(generatedTools.map(tool => [tool.name, tool]));
  const entries = new Map<string, Tool>(customTools.map(tool => [
    tool.name, {
      name: tool.name,
      description: tool.description,
      safety: tool.safety,
      usesSnapshot: tool.category !== 'Discovery',
      inputSchema: tool.inputSchema,
      invoke: args => tool.handler(client, args),
    },
  ]));
  entries.set('refresh_ad_cache', {
    name: 'refresh_ad_cache',
    description: 'Start an AD snapshot refresh or report the active scan.',
    safety: 'write',
    usesSnapshot: false,
    inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    invoke: async () => cache.start(),
  });

  for (const name of enabledNames) {
    if (entries.has(name)) continue;
    const tool = generated.get(name);
    if (!tool) throw new Error(`Unknown tool in TIE_ENABLED_TOOLS: ${name}`);
    // The bundled schemas are inlined without $refs. Some reuse $ids or
    // declare a required parameter twice when it is both a path and query input.
    const schema = JSON.parse(JSON.stringify(tool.inputSchema, (key, value) => {
      if (key === '$id') return undefined;
      return key === 'required' && Array.isArray(value) ? [...new Set(value)] : value;
    })) as ToolInputSchema;
    entries.set(name, {
      name,
      description: tool.description,
      safety: tool.safety,
      usesSnapshot: false,
      inputSchema: { ...schema, additionalProperties: false },
      invoke: args => {
        if (tool.pathParams.some(key => args[key] === '.' || args[key] === '..' || args[key] === '')) {
          throw new RequestError(400, 'Invalid tool path parameter');
        }
        return dispatchTool(client, tool, args);
      },
    });
  }
  const validators = new Map<string, ValidateFunction>(
    [...entries].map(([name, tool]) => [name, ajv.compile(tool.inputSchema)])
  );
  const catalog = [...entries.values()].map(({ name, description, safety, inputSchema }) => ({
    name, description, safety, inputSchema,
  }));
  return { entries, validators, catalog };
}

function main(): void {
  const config = loadConfig();
  const client = new TIEClient(config);
  const storeOptions = {
    ttlMs: config.cacheTtlMs,
    warmConcurrency: config.warmConcurrency,
    warmChunk: config.warmChunk,
    managedRefresh: true,
  };
  configureStore({ ...storeOptions, baseUrl: config.baseUrl });
  const cache = new CacheLifecycle(
    () => new ADObjectStore(client, storeOptions), setSharedStore, config.refreshIntervalMs,
  );
  const { entries, validators, catalog } = registerTools(client, cache, config.enabledTools);
  const tokenDigest = digest(config.connectorToken);
  let identityDone: Promise<unknown> = Promise.resolve();

  const server = createServer(async (request, response) => {
    const token = /^Bearer (\S+)$/i.exec(request.headers.authorization ?? '')?.[1];
    if (!token || !timingSafeEqual(digest(token), tokenDigest)) {
      response.setHeader('WWW-Authenticate', 'Bearer');
      send(response, 401, { error: 'A valid connector bearer token is required' });
      return;
    }

    try {
      const path = request.url?.split('?', 1)[0] ?? '';
      if (request.method === 'GET' && path === '/v1/status') {
        send(response, 200, cache.status());
        return;
      }
      const list = request.method === 'GET' && path === '/v1/tools';
      const name = request.method === 'POST' ? /^\/v1\/tools\/([a-z0-9_]+)$/.exec(path)?.[1] : undefined;
      const tool = name ? entries.get(name) : undefined;
      if (!list && !tool) throw new RequestError(404, 'Unknown route or disabled tool');

      if (name !== 'refresh_ad_cache' && !cache.ready()) {
        response.setHeader('Retry-After', RETRY_SECONDS);
        send(response, 503, cache.status());
        return;
      }
      if (list) {
        send(response, 200, { tools: catalog });
        return;
      }
      if (!tool) throw new RequestError(404, 'Unknown route or disabled tool');

      const args = await readArgs(request);
      const validate = validators.get(tool.name)!;
      if (!validate(args)) {
        throw new RequestError(400, `Invalid tool arguments: ${validate.errors?.[0]?.message}`);
      }

      if (name === 'refresh_ad_cache') {
        response.setHeader('Retry-After', RETRY_SECONDS);
        send(response, 202, await tool.invoke(args));
        return;
      }
      // Recheck after reading the body, since another caller may have started a scan.
      if (!cache.ready()) {
        response.setHeader('Retry-After', RETRY_SECONDS);
        send(response, 503, cache.status());
        return;
      }
      if ((name === 'query_ad_objects' || name === 'get_ad_object') && args.refresh === true) {
        response.setHeader('Retry-After', RETRY_SECONDS);
        send(response, 202, cache.start());
        return;
      }
      const invoke = () => cache.run(() => tool.invoke(args));
      const execute = () => {
        if (name === 'get_identity_360' || name === 'get_identity_360_summary') {
          // The shared deviance index holds one profile at a time.
          const pending = identityDone.then(invoke);
          identityDone = pending.catch(() => {});
          return pending;
        }
        return invoke();
      };
      const { result, adSnapshot } = await execute();
      const directLive = name === 'get_ad_object' && args.liveData === true && args.id !== undefined;
      const freshness = {
        ...(tool.usesSnapshot && (!directLive || args.decodeSecurityDescriptor === true) && { adSnapshot }),
        ...(!tool.usesSnapshot && { upstream: liveFreshness() }),
        refreshInProgress: cache.refreshInProgress,
      };
      if (result && typeof result === 'object' && !Array.isArray(result)) {
        const object = result as Args;
        if (tool.usesSnapshot || !Object.hasOwn(object, 'freshness')) {
          send(response, 200, { ...object, freshness: { ...freshness, ...(tool.usesSnapshot ? object.freshness as Args : {}) } });
          return;
        }
      }
      send(response, 200, { data: result ?? null, freshness });
    } catch (error) {
      if (response.headersSent || response.destroyed) return;
      if (error instanceof RequestError) send(response, error.statusCode, { error: error.message });
      else if (error instanceof TIEUpstreamError) send(response, 502, { error: error.message });
      else {
        console.error('TIE connector request failed:', error);
        send(response, 500, { error: 'TIE connector request failed' });
      }
    }
  });
  server.listen(config.port, '0.0.0.0', () => {
    cache.start('startup');
    console.log(`TIE connector listening on port ${config.port}`);
  });
}

main();
