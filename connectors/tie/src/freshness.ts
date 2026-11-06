export function scanFreshness(
  source: 'ad_snapshot' | 'deviance_snapshot',
  startedAt: number,
  completedAt: number,
  ttlMs: number,
) {
  const ageMs = Math.max(0, Date.now() - completedAt);
  return {
    source,
    scanStartedAt: new Date(startedAt).toISOString(),
    scanCompletedAt: new Date(completedAt).toISOString(),
    ageMs,
    stale: ageMs >= ttlMs,
  };
}

export function liveFreshness() {
  return { source: 'tie_live' as const, fetchedAt: new Date().toISOString() };
}
