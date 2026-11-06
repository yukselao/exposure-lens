<?php
declare(strict_types=1);

/** The sync_jobs queue, as seen from the web UI. The connectors pick the jobs up. */
final class Jobs
{
    private const COLUMNS = "id, connector, kind, target_id, trigger, status, requested_at, started_at,
        finished_at, progress, stats, error,
        EXTRACT(EPOCH FROM COALESCE(finished_at, now()) - started_at)::int AS duration_seconds";

    /** Queue a job unless the same one is already queued or running. Returns the job id either way. */
    public static function enqueue(string $connector, string $kind, ?int $targetId): array
    {
        $existing = Db::value(
            "SELECT id FROM sync_jobs WHERE connector = ? AND kind = ? AND status IN ('queued', 'running')
             AND target_id IS NOT DISTINCT FROM ? ORDER BY id DESC LIMIT 1",
            [$connector, $kind, $targetId]
        );
        if ($existing) {
            return ['id' => (int) $existing, 'already_queued' => true];
        }
        $id = Db::value(
            "INSERT INTO sync_jobs (connector, kind, target_id, trigger) VALUES (?, ?, ?, 'manual') RETURNING id",
            [$connector, $kind, $targetId]
        );
        return ['id' => (int) $id, 'already_queued' => false];
    }

    public static function get(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::COLUMNS . ' FROM sync_jobs WHERE id = ?', [$id]);
        return $row ? self::decode($row) : null;
    }

    public static function recent(string $connector, ?string $kind, ?int $targetId, int $limit): array
    {
        $where = ['connector = ?'];
        $params = [$connector];
        if ($kind !== null) {
            $where[] = 'kind = ?';
            $params[] = $kind;
        }
        if ($targetId !== null) {
            $where[] = 'target_id = ?';
            $params[] = $targetId;
        }
        $params[] = max(1, min(100, $limit));
        $rows = Db::all(
            'SELECT ' . self::COLUMNS . ' FROM sync_jobs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ?',
            $params
        );
        return array_map([self::class, 'decode'], $rows);
    }

    public static function isBusy(string $connector, ?int $targetId): bool
    {
        return (bool) Db::value(
            "SELECT 1 FROM sync_jobs WHERE connector = ? AND kind = 'sync' AND status = 'running'
             AND target_id IS NOT DISTINCT FROM ? LIMIT 1",
            [$connector, $targetId]
        );
    }

    private static function decode(array $row): array
    {
        $row['progress'] = json_decode((string) $row['progress'], true) ?: new stdClass();
        $row['stats'] = json_decode((string) $row['stats'], true) ?: new stdClass();
        return $row;
    }
}
