import { readFileSync } from 'node:fs';

export interface TIEConfig {
  baseUrl: string;
  apiKey: string;
  timeout: number;
  cacheTtlMs: number;
  refreshIntervalMs: number;
  warmConcurrency: number;
  warmChunk: number;
  port: number;
  connectorToken: string;
  enabledTools: string[];
  logFile?: string;
}

function integer(name: string, fallback: number, minimum: number, maximum = Number.MAX_SAFE_INTEGER): number {
  const raw = process.env[name];
  if (raw === undefined) return fallback;
  const value = Number(raw);
  if (!/^\d+$/.test(raw) || !Number.isSafeInteger(value) || value < minimum || value > maximum) {
    throw new Error(`${name} must be an integer between ${minimum} and ${maximum}`);
  }
  return value;
}

export function loadConfig(): TIEConfig {
  const baseUrl = process.env.TIE_BASE_URL;
  const apiKey = process.env.TIE_API_KEY;
  const tokenFile = process.env.TIE_CONNECTOR_TOKEN_FILE;
  if (!baseUrl || !apiKey || !tokenFile) {
    throw new Error('TIE_BASE_URL, TIE_API_KEY, and TIE_CONNECTOR_TOKEN_FILE are required');
  }

  const url = URL.parse(baseUrl);
  if (!url || !['http:', 'https:'].includes(url.protocol) ||
      url.username || url.password || url.search || url.hash) {
    throw new Error('TIE_BASE_URL must be an HTTP(S) URL without credentials, a query, or a fragment');
  }
  const connectorToken = readFileSync(tokenFile, 'utf8').trim();
  if (!/^[A-Za-z0-9._~+/-]+=*$/.test(connectorToken)) {
    throw new Error('Connector token file must contain a valid bearer token');
  }

  return {
    baseUrl: url.href.replace(/\/+$/, ''),
    apiKey,
    connectorToken,
    timeout: integer('TIE_TIMEOUT', 30000, 1),
    cacheTtlMs: integer('TIE_CACHE_TTL_MS', 86400000, 1),
    refreshIntervalMs: integer('TIE_CACHE_REFRESH_INTERVAL_MINUTES', 0, 0, 35791) * 60_000,
    warmConcurrency: integer('TIE_WARM_CONCURRENCY', 5, 1),
    warmChunk: integer('TIE_WARM_CHUNK', 5000, 1000),
    port: integer('PORT', 8080, 1, 65535),
    enabledTools: (process.env.TIE_ENABLED_TOOLS ?? '').split(',').map(name => name.trim()).filter(Boolean),
    logFile: process.env.TIE_LOG_FILE?.trim() || undefined,
  };
}
