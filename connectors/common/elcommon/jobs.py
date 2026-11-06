"""The sync_jobs queue. Every function here runs in its own short transaction,
so job status and progress are visible while a sync holds a long transaction."""
from sqlalchemy import text

from eldb.database import get_engine


def recover_interrupted(connector: str) -> int:
    """Fail jobs left `running` by a previous process of this connector."""
    with get_engine().begin() as conn:
        result = conn.execute(text("""
            UPDATE sync_jobs SET status = 'failed', finished_at = now(),
                   error = 'Interrupted: the connector restarted while this job was running.'
            WHERE connector = :c AND status = 'running'"""), {"c": connector})
        return result.rowcount


def claim(connector: str):
    """Take the oldest queued job of this connector, or None."""
    with get_engine().begin() as conn:
        row = conn.execute(text("""
            UPDATE sync_jobs SET status = 'running', started_at = now(), heartbeat_at = now()
            WHERE id = (SELECT id FROM sync_jobs
                        WHERE connector = :c AND status = 'queued'
                        ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1)
            RETURNING id, kind, target_id, trigger"""), {"c": connector}).mappings().first()
        return dict(row) if row else None


def progress(job_id: int, **fields) -> None:
    """Merge `fields` into the job's progress and refresh its heartbeat."""
    import json
    with get_engine().begin() as conn:
        conn.execute(text("""
            UPDATE sync_jobs SET progress = progress || CAST(:p AS jsonb), heartbeat_at = now()
            WHERE id = :id"""), {"id": job_id, "p": json.dumps(fields, default=str)})


def finish(job_id: int, stats: dict) -> None:
    import json
    with get_engine().begin() as conn:
        conn.execute(text("""
            UPDATE sync_jobs SET status = 'succeeded', finished_at = now(), heartbeat_at = now(),
                   stats = CAST(:s AS jsonb), error = NULL WHERE id = :id"""),
                     {"id": job_id, "s": json.dumps(stats, default=str)})


def fail(job_id: int, error: str, stats: dict | None = None) -> None:
    import json
    with get_engine().begin() as conn:
        conn.execute(text("""
            UPDATE sync_jobs SET status = 'failed', finished_at = now(), heartbeat_at = now(),
                   stats = CAST(:s AS jsonb), error = :e WHERE id = :id"""),
                     {"id": job_id, "e": error[:4000], "s": json.dumps(stats or {}, default=str)})


def enqueue_if_idle(connector: str, kind: str, target_id, trigger: str) -> bool:
    """Queue a job unless one is already queued or running for the same target."""
    with get_engine().begin() as conn:
        result = conn.execute(text("""
            INSERT INTO sync_jobs (connector, kind, target_id, trigger)
            SELECT :c, :k, :t, :tr
            WHERE NOT EXISTS (
                SELECT 1 FROM sync_jobs
                WHERE connector = :c AND kind = :k AND status IN ('queued', 'running')
                  AND target_id IS NOT DISTINCT FROM :t)"""),
            {"c": connector, "k": kind, "t": target_id, "tr": trigger})
        return result.rowcount > 0


def minutes_since_last(connector: str, kind: str, target_id):
    """Minutes since the last job for this target ended (or was requested, if
    it never ended). None when there has been no job."""
    with get_engine().begin() as conn:
        return conn.execute(text("""
            SELECT EXTRACT(EPOCH FROM now() - COALESCE(finished_at, requested_at)) / 60.0
            FROM sync_jobs
            WHERE connector = :c AND kind = :k AND target_id IS NOT DISTINCT FROM :t
            ORDER BY id DESC LIMIT 1"""), {"c": connector, "k": kind, "t": target_id}).scalar()
