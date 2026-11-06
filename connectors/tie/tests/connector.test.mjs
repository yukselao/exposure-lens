import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { test } from 'node:test';
import { CacheLifecycle } from '../build/cache.js';
import { tools as generated } from '../build/generated/tools.js';

const auth = { Authorization: 'Bearer connector-test-token' };

async function listen(server) {
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return server.address().port;
}

async function until(check) {
  for (let attempt = 0; attempt < 150; attempt++) {
    if (await check()) return;
    await delay(20);
  }
  assert.fail('Timed out waiting for connector state');
}

async function startConnector(t, upstreamPort, enabledTools = '') {
  const tokenDir = await mkdtemp(join(tmpdir(), 'tie-connector-test-'));
  const tokenFile = join(tokenDir, 'token');
  const logFile = join(tokenDir, 'connector.log');
  await writeFile(tokenFile, 'connector-test-token\n');
  t.after(() => rm(tokenDir, { recursive: true, force: true }));
  const probe = createServer();
  const port = await listen(probe);
  await new Promise(resolve => probe.close(resolve));

  const connector = spawn(process.execPath, ['build/index.js'], {
    env: {
      ...process.env,
      PORT: String(port),
      TIE_BASE_URL: `http://127.0.0.1:${upstreamPort}`,
      TIE_API_KEY: 'upstream-test-key',
      TIE_CONNECTOR_TOKEN_FILE: tokenFile,
      TIE_LOG_FILE: logFile,
      TIE_WARM_CONCURRENCY: '1',
      TIE_ENABLED_TOOLS: enabledTools,
      TIE_CACHE_REFRESH_INTERVAL_MINUTES: '0',
      NO_PROXY: '127.0.0.1',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let output = '';
  let stdout = '';
  connector.stdout.on('data', chunk => { stdout += chunk; });
  connector.stderr.on('data', chunk => { output += chunk; });
  t.after(async () => {
    if (connector.exitCode !== null) return;
    const exit = once(connector, 'exit');
    connector.kill();
    await exit;
  });

  async function call(path, body, headers = auth) {
    return fetch(`http://127.0.0.1:${port}${path}`, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  }
  const status = async () => (await call('/v1/status')).json();
  const tool = (name, body = {}) => call(`/v1/tools/${name}`, body);
  await until(async () => {
    assert.equal(connector.exitCode, null, output);
    try {
      return (await status()).state;
    } catch {
      return false;
    }
  });
  return { call, status, tool, logFile, stdout: () => stdout };
}

function adObject(id, classes) {
  return {
    id, directoryId: 1, objectId: String(id), type: 'LDAP',
    objectAttributes: [
      { name: 'objectClass', value: JSON.stringify(classes), valueType: 'array/string' },
      { name: 'samAccountName', value: JSON.stringify(`account-${id}`), valueType: 'string' },
    ],
  };
}

test('authenticates, reports a paged warm-up, and refreshes the searchable snapshot', async t => {
  let scanStarts = 0;
  let releasePage;
  let failNextScan = false;
  let liveLookups = 0;
  const upstream = createServer(async (request, response) => {
    assert.equal(request.headers['x-api-key'], 'upstream-test-key');
    response.setHeader('Content-Type', 'application/json');
    const detail = /^\/api\/directories\/1\/ad-objects\/(\d+)$/.exec(request.url);
    if (detail) {
      liveLookups++;
      const id = Number(detail[1]);
      if (id === 99 || id === 98) {
        response.writeHead(id === 99 ? 404 : 500).end('{}');
      } else {
        response.end(JSON.stringify(adObject(id, ['live'])));
      }
      return;
    }
    assert.match(request.url, /^\/api\/ad-objects\?lastIdentifierSeen=/);
    const first = request.url.endsWith('=0');
    if (first) {
      scanStarts++;
      if (failNextScan) {
        failNextScan = false;
        response.writeHead(500).end('{}');
        return;
      }
    } else {
      await new Promise(resolve => { releasePage = resolve; });
    }
    const id = (scanStarts - 1) * 2 + (first ? 1 : 2);
    response.end(JSON.stringify({
      _embedded: { 'ad-objects': [adObject(id, first ? ['user'] : ['user', 'computer'])] },
      _links: first ? { next: `/api/ad-objects?lastIdentifierSeen=${id}` } : {},
    }));
  });
  const port = await listen(upstream);
  t.after(() => { releasePage?.(); upstream.closeAllConnections(); upstream.close(); });
  const { call, status, tool, logFile, stdout } = await startConnector(t, port);

  await until(() => Boolean(releasePage));
  assert.equal((await call('/v1/status', undefined, {})).status, 401);
  assert.equal((await call('/v1/status', undefined, { Authorization: 'Bearer wrong' })).status, 401);
  assert.equal((await call('/v1/status?trace=private-query-marker')).status, 200);
  const blocked = await tool('query_ad_objects');
  assert.equal(blocked.status, 503);
  assert.ok(blocked.headers.get('Retry-After'));
  const progress = await blocked.json();
  assert.equal(progress.state, 'warming');
  assert.equal(progress.pagesFetched, 1);
  assert.equal(progress.objectsFetched, 1);
  assert.equal((await tool('refresh_ad_cache')).status, 202);
  assert.equal(scanStarts, 1);
  releasePage();
  releasePage = undefined;

  await until(async () => (await status()).state === 'ready');
  const catalog = await (await call('/v1/tools')).json();
  assert.equal(catalog.tools.length, 11);
  assert.equal((await tool('get_about')).status, 404);
  assert.equal((await tool('query_ad_objects', { limit: -1 })).status, 400);
  const all = await (await tool('query_ad_objects', { limit: 0 })).json();
  assert.equal(all.total, 2);
  assert.deepEqual(all.objects.map(object => object.id), [1, 2]);
  assert.equal(all.freshness.adSnapshot.source, 'ad_snapshot');
  assert.ok(Date.parse(all.freshness.adSnapshot.scanStartedAt) <= Date.parse(all.freshness.adSnapshot.scanCompletedAt));
  const users = await (await tool('query_ad_objects', {
    expression: 'objectClass=user AND NOT objectClass=computer', limit: 0,
  })).json();
  assert.deepEqual(users.objects.map(object => object.id), [1]);

  const live = await (await tool('get_ad_object', { samAccountName: 'account-1', liveData: true })).json();
  assert.equal(live.object.id, 1);
  assert.equal(live.object.attributes[0].value, '["live"]');
  assert.equal(live.freshness.object.source, 'tie_live');
  assert.ok(Date.parse(live.freshness.object.fetchedAt));
  assert.equal(live.freshness.adSnapshot.source, 'ad_snapshot');

  const direct = await (await tool('get_ad_object', { directoryId: 1, id: 30, liveData: true })).json();
  assert.equal(direct.object.id, 30);
  assert.equal(direct.freshness.adSnapshot, undefined);
  assert.equal(direct.freshness.object.source, 'tie_live');
  const missing = await (await tool('get_ad_object', { directoryId: 1, id: 99, liveData: true })).json();
  assert.equal(missing.found, false);
  assert.equal(missing.reason, 'not_found_in_tie');
  assert.equal(missing.freshness.object.source, 'tie_live');
  assert.equal((await tool('get_ad_object', { directoryId: 1, id: 98, liveData: true })).status, 502);
  const cacheMiss = await (await tool('get_ad_object', { samAccountName: 'unknown', liveData: true })).json();
  assert.equal(cacheMiss.reason, 'not_found_in_snapshot');
  assert.equal(cacheMiss.freshness.object, undefined);
  assert.equal((await tool('get_ad_object', { directoryId: 1, id: 30 })).status, 400);
  assert.equal((await tool('get_ad_object', { samAccountName: 'account-1', liveData: true, refresh: true })).status, 400);
  assert.equal(liveLookups, 4);
  const cached = await (await tool('get_ad_object', { samAccountName: 'account-1' })).json();
  assert.equal(cached.object.attributes[0].value, '["user"]');

  failNextScan = true;
  assert.equal((await tool('refresh_ad_cache')).status, 202);
  await until(async () => (await status()).state === 'failed');
  assert.equal((await tool('query_ad_objects')).status, 503);
  const refresh = await tool('refresh_ad_cache');
  assert.equal(refresh.status, 202);
  assert.equal((await refresh.json()).state, 'refreshing');
  await until(() => Boolean(releasePage));
  assert.equal((await tool('query_ad_objects')).status, 503);
  assert.equal((await tool('refresh_ad_cache')).status, 202);
  assert.equal(scanStarts, 3);
  releasePage();
  await until(async () => (await status()).state === 'ready');
  const refreshed = await (await tool('query_ad_objects', { limit: 0 })).json();
  assert.deepEqual(refreshed.objects.map(object => object.id), [5, 6]);

  const hasCompletedRefresh = logs => logs.includes('"event":"ad_sync_completed","trigger":"manual"');
  await until(async () => hasCompletedRefresh(stdout()) && hasCompletedRefresh(await readFile(logFile, 'utf8')));
  for (const output of [stdout(), await readFile(logFile, 'utf8')]) {
    const logs = output.trim().split('\n').map(line => JSON.parse(line));
    assert.ok(logs.some(log => log.event === 'connector_started'));
    assert.ok(logs.some(log => log.event === 'ad_sync_progress' && log.pagesFetched === 1));
    assert.ok(logs.some(log => log.event === 'ad_sync_failed' && log.level === 'error'));
    assert.ok(logs.some(log => log.event === 'http_request' && log.route === '/v1/status' && log.status === 401));
    assert.ok(logs.some(log => log.event === 'http_request' && log.route === '/v1/tools/get_ad_object' && log.status === 200));
    assert.doesNotMatch(output, /upstream-test-key|connector-test-token|account-1|private-query-marker/);
  }
});

test('can opt into every generated tool and dispatch an enabled operation', async t => {
  let scans = 0;
  const upstream = createServer((request, response) => {
    response.setHeader('Content-Type', 'application/json');
    if (request.url === '/api/about') {
      response.end(JSON.stringify({ product: 'TIE' }));
      return;
    }
    assert.match(request.url, /^\/api\/ad-objects\?/);
    scans++;
    response.end(JSON.stringify({ _embedded: { 'ad-objects': [] } }));
  });
  const port = await listen(upstream);
  t.after(() => { upstream.closeAllConnections(); upstream.close(); });
  const { call, status, tool } = await startConnector(t, port, generated.map(tool => tool.name).join(','));
  await until(async () => (await status()).state === 'ready');
  const catalog = await (await call('/v1/tools')).json();
  assert.equal(catalog.tools.length, generated.length + 11);
  const about = await (await tool('get_about')).json();
  assert.equal(about.product, 'TIE');
  assert.equal(about.freshness.upstream.source, 'tie_live');
  assert.equal((await (await tool('query_ad_objects', { limit: 0 })).json()).total, 0);
  assert.equal(scans, 1);
});

test('scheduled refreshes publish only complete snapshots and retain the previous one on failure', async () => {
  let activeGeneration = 0;
  let scans = 0;
  let finish;
  let fail;
  const logEvents = [];
  const cache = new CacheLifecycle(() => {
    const generation = ++scans;
    const startedAt = Date.now();
    return {
      generation,
      ensureLoaded: () => generation === 1 ? Promise.resolve() : new Promise((resolve, reject) => {
        finish = resolve;
        fail = reject;
      }),
      ensureConsolidated: () => ({ count: generation }),
      stats: () => ({ scanStartedAt: startedAt, builtAt: startedAt, ttlMs: 1, fresh: false }),
    };
  }, store => { activeGeneration = store.generation; }, 100, {
    info: (event, fields) => logEvents.push({ event, ...fields }),
    error: (event, fields) => logEvents.push({ event, ...fields }),
  });

  cache.start('startup');
  await until(() => cache.status().state === 'ready');
  assert.equal(activeGeneration, 1);
  assert.ok(cache.status().nextRefreshAt);
  await until(() => cache.status().trigger === 'scheduled');
  assert.equal(cache.ready(), true);
  assert.equal(activeGeneration, 1);
  assert.equal(cache.status().freshness.refreshInProgress, true);
  assert.equal(cache.start().trigger, 'scheduled');
  assert.equal(scans, 2);

  let finishRead;
  const originalFreshness = cache.status().freshness.adSnapshot.scanStartedAt;
  const inFlight = cache.run(() => new Promise(resolve => { finishRead = resolve; }));
  finish();
  await until(() => activeGeneration === 2);
  finishRead('from generation 1');
  assert.equal((await inFlight).adSnapshot.scanStartedAt, originalFreshness);

  await until(() => scans === 3);
  fail(new Error('test scan failure'));
  await until(() => cache.status().state === 'failed');
  assert.equal(cache.ready(), true);
  assert.equal(activeGeneration, 2);
  assert.equal(cache.status().objectCount, 2);
  assert.ok(cache.status().nextRefreshAt);
  assert.ok(logEvents.some(log => log.event === 'ad_sync_failed' && log.trigger === 'scheduled' && log.nextRefreshAt));
});
