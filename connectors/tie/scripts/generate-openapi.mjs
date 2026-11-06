import { writeFile } from 'node:fs/promises';
import { customTools } from '../build/custom-tools.js';

const schema = name => ({ $ref: `#/components/schemas/${name}` });
const response = name => ({ $ref: `#/components/responses/${name}` });
const json = (description, body) => ({
  description,
  content: { 'application/json': { schema: body } },
});
const object = (properties, required) => ({
  type: 'object', properties, ...(required && { required }),
});
const array = items => ({ type: 'array', items });
const integer = { type: 'integer' };
const string = { type: 'string' };
const boolean = { type: 'boolean' };
const timestamp = { type: 'string', format: 'date-time' };
const nullable = item => ({ anyOf: [item, { type: 'null' }] });
const result = properties => object({
  freshness: schema('Freshness'),
  error: string,
  ...properties,
}, ['freshness']);

const toolResults = {
  get_topology: result({ forests: array({ type: 'object' }), totals: { type: 'object' } }),
  get_preferred_profile: result({ preferredProfileId: nullable(integer), preferredProfileName: nullable(string) }),
  query_ad_objects: result({
    expression: nullable(string), total: integer, returned: integer, truncated: boolean,
    snapshot: { type: 'object' }, objects: array(schema('ADObject')), message: string, position: integer,
  }),
  get_ad_object: result({
    found: boolean, reason: { enum: ['not_found_in_snapshot', 'not_found_in_tie'] },
    object: schema('ADObject'), securityDescriptor: { type: 'object' }, snapshot: { type: 'object' },
    id: integer, directoryId: integer, searchedBy: string, value: string,
  }),
  get_blast_radius: result({
    principal: string, found: boolean, reachableCount: integer, truncated: { type: ['string', 'null'] },
    graph: { type: 'object' }, reachable: array({ type: 'object' }),
  }),
  get_control_paths: result({
    from: string, to: string, found: boolean, reachable: boolean, hops: integer, path: array({ type: 'object' }),
    truncated: { type: ['string', 'null'] },
  }),
  get_asset_exposure: result({
    targets: array(string), exposedCount: integer, truncated: { type: ['string', 'null'] },
    graph: { type: 'object' }, exposedPrincipals: array({ type: 'object' }),
  }),
  get_tier0: result({
    builtinCount: integer, derivedCount: integer, tier0Total: integer, truncated: { type: ['string', 'null'] },
    graph: { type: 'object' }, builtin: array({ type: 'object' }), derived: array({ type: 'object' }),
  }),
  get_identity_360: result({
    identity: { type: 'object' }, summary: { type: 'object' }, deviances: array({ type: 'object' }), meta: { type: 'object' },
  }),
  get_identity_360_summary: result({
    identities: array({ type: 'object' }), unresolved: integer, meta: { type: 'object' },
  }),
};

const examples = {
  query_ad_objects: {
    fullSnapshot: { value: { limit: 0 } },
    users: { value: { expression: 'objectClass=user AND NOT objectClass=computer' } },
  },
  get_ad_object: {
    cached: { value: { samAccountName: 'analyst' } },
    liveByName: { value: { samAccountName: 'analyst', liveData: true } },
    liveByIds: { value: { directoryId: 3, id: 90266, liveData: true } },
  },
};

const errors = {
  400: response('BadRequest'), 401: response('Unauthorized'), 413: response('TooLarge'),
  500: response('InternalError'), 502: response('UpstreamError'), 503: response('Unavailable'),
};
const toolPaths = Object.fromEntries(customTools.map(tool => [
  `/v1/tools/${tool.name}`,
  {
    post: {
      operationId: tool.name,
      tags: ['Tools'],
      summary: tool.name,
      description: tool.name === 'get_ad_object'
        ? `${tool.description} A live miss returns found:false with reason not_found_in_tie. A name absent from the cache returns not_found_in_snapshot without contacting Tenable.`
        : tool.description,
      requestBody: {
        required: Boolean(tool.inputSchema.required?.length || tool.inputSchema.oneOf),
        content: {
          'application/json': {
            schema: tool.inputSchema,
            ...(examples[tool.name] && { examples: examples[tool.name] }),
          },
        },
      },
      responses: {
        200: json('Tool result with freshness metadata. Domain-level errors can appear in the result.', toolResults[tool.name]),
        ...(['get_ad_object', 'query_ad_objects'].includes(tool.name) && { 202: response('ScanAccepted') }),
        ...errors,
      },
    },
  },
]));

const retryAfter = {
  'Retry-After': { schema: { type: 'integer', example: 3 }, description: 'Suggested delay in seconds before polling again.' },
};
const spec = {
  openapi: '3.1.0',
  info: {
    title: 'Exposure Lens TIE Connector',
    version: '1.0.0',
    description: 'Private JSON API for Tenable Identity Exposure. Use GET /v1/tools for the tool catalog enabled on the running connector.',
  },
  servers: [{ url: 'http://tie:8080', description: 'Private Docker Compose network' }],
  security: [{ bearerAuth: [] }],
  paths: {
    '/v1/status': {
      get: {
        operationId: 'getStatus',
        summary: 'Read cache status and schedule',
        responses: { 200: json('Cache state in all phases.', schema('CacheStatus')), 401: response('Unauthorized') },
      },
    },
    '/v1/tools': {
      get: {
        operationId: 'listTools',
        summary: 'List enabled tools and argument schemas',
        responses: {
          200: json('Tool catalog.', object({
            tools: array(object({
              name: string, description: string,
              safety: { enum: ['read', 'write', 'destructive'] },
              inputSchema: { type: 'object' },
            }, ['name', 'description', 'safety', 'inputSchema'])),
          }, ['tools'])),
          401: response('Unauthorized'), 503: response('Unavailable'),
        },
      },
    },
    '/v1/tools/refresh_ad_cache': {
      post: {
        operationId: 'refresh_ad_cache',
        tags: ['Tools'],
        summary: 'Start or observe an AD snapshot scan',
        description: 'Returns the active scan if one is already running. Poll GET /v1/status for completion.',
        requestBody: { content: { 'application/json': { schema: { type: 'object', additionalProperties: false } } } },
        responses: { 202: response('ScanAccepted'), 400: response('BadRequest'), 401: response('Unauthorized'), 413: response('TooLarge') },
      },
    },
    ...toolPaths,
    '/v1/tools/{tool_name}': {
      post: {
        operationId: 'invokeEnabledTool',
        tags: ['Tools'],
        summary: 'Invoke an optionally enabled Tenable API tool',
        description: 'Use the input schema from GET /v1/tools. Object results keep their top-level fields. Arrays, scalars, and objects with an upstream freshness field are returned under data.',
        parameters: [{ name: 'tool_name', in: 'path', required: true, schema: { type: 'string', pattern: '^[a-z0-9_]+$' } }],
        requestBody: { content: { 'application/json': { schema: { type: 'object' } } } },
        responses: {
          200: json('Tool result with upstream fetch metadata.', result({ data: {} })),
          404: response('UnknownTool'), ...errors,
        },
      },
    },
  },
  components: {
    securitySchemes: { bearerAuth: { type: 'http', scheme: 'bearer', description: 'Dedicated connector token.' } },
    schemas: {
      Error: object({ error: string }, ['error']),
      ScanFreshness: object({
        source: { enum: ['ad_snapshot', 'deviance_snapshot'] },
        scanStartedAt: timestamp,
        scanCompletedAt: timestamp,
        ageMs: { type: 'integer', minimum: 0, description: 'Elapsed time since the scan completed.' },
        stale: { type: 'boolean', description: 'Whether the age meets or exceeds the configured TTL.' },
      }, ['source', 'scanStartedAt', 'scanCompletedAt', 'ageMs', 'stale']),
      LiveFreshness: object({
        source: { const: 'tie_live' },
        fetchedAt: { ...timestamp, description: 'Connector retrieval time from TIE.' },
      }, ['source', 'fetchedAt']),
      Freshness: object({
        refreshInProgress: { type: 'boolean', description: 'Whether an AD snapshot scan is in progress when the response is sent.' },
        adSnapshot: nullable(schema('ScanFreshness')),
        devianceIndex: schema('ScanFreshness'),
        object: schema('LiveFreshness'),
        upstream: schema('LiveFreshness'),
      }, ['refreshInProgress']),
      CacheStatus: object({
        state: { enum: ['warming', 'refreshing', 'ready', 'failed'] },
        phase: { enum: ['waiting', 'scanning', 'consolidating', 'complete', 'failed'] },
        trigger: { enum: ['startup', 'manual', 'scheduled', 'expiry'] },
        pagesFetched: integer,
        objectsFetched: integer,
        elapsedMs: integer,
        objectCount: nullable(integer),
        nextRefreshAt: nullable(timestamp),
        freshness: schema('Freshness'),
        error: string,
      }, ['state', 'phase', 'trigger', 'pagesFetched', 'objectsFetched', 'elapsedMs', 'objectCount', 'nextRefreshAt', 'freshness']),
      ADObject: object({
        id: integer, directoryId: integer, objectId: string, type: string,
        attributes: array(object({ name: string, value: {}, valueType: string }, ['name', 'value', 'valueType'])),
        derived: { type: 'object' },
      }, ['id', 'directoryId', 'objectId', 'type', 'attributes']),
    },
    responses: {
      BadRequest: json('Invalid JSON or tool arguments.', schema('Error')),
      Unauthorized: {
        ...json('A valid bearer token is required.', schema('Error')),
        headers: { 'WWW-Authenticate': { schema: { const: 'Bearer' } } },
      },
      UnknownTool: json('Unknown route or disabled tool.', schema('Error')),
      TooLarge: json('The JSON body exceeds 1 MiB.', schema('Error')),
      InternalError: json('Connector failure.', schema('Error')),
      UpstreamError: json('Tenable API request failed.', schema('Error')),
      Unavailable: {
        ...json('The cache is warming, blocked by a foreground refresh, or unavailable after a failed scan.', schema('CacheStatus')),
        headers: retryAfter,
      },
      ScanAccepted: {
        ...json('Started a scan or joined the active scan.', schema('CacheStatus')),
        headers: retryAfter,
      },
    },
  },
};

await writeFile(new URL('../openapi.json', import.meta.url), `${JSON.stringify(spec, null, 2)}\n`);
