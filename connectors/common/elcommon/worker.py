"""The connector main loop: every POLL_INTERVAL_SECONDS, queue the syncs that
are due, then run the queued jobs one at a time."""
import os
import signal
import threading
import time

from . import jobs


class JobError(Exception):
    """A failure with a message that is safe and useful to show in the UI."""


def run(connector: str, log, handlers: dict, schedule=None) -> None:
    """`handlers` maps a job kind to `fn(job) -> stats dict`. `schedule()` is
    called on every tick to enqueue due work."""
    interval = max(1, int(os.environ.get("POLL_INTERVAL_SECONDS", "30")))
    stop = threading.Event()

    def _stop(signum, _frame):
        log.info("connector_stopping", signal=signal.Signals(signum).name)
        stop.set()

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)

    log.info("connector_started", poll_interval_seconds=interval, job_kinds=sorted(handlers))
    while not stop.is_set():
        try:
            recovered = jobs.recover_interrupted(connector)
            if recovered:
                log.warning("jobs_recovered_after_restart", count=recovered)
            break
        except Exception:
            log.exception("database_unavailable")
            stop.wait(interval)

    while not stop.is_set():
        try:
            if schedule:
                schedule()
            while not stop.is_set():
                job = jobs.claim(connector)
                if not job:
                    break
                _run_job(log, handlers, job)
        except Exception:
            log.exception("worker_tick_failed")
        stop.wait(interval)
    log.info("connector_stopped")


def _run_job(log, handlers, job) -> None:
    started = time.monotonic()
    fields = {"job_id": job["id"], "kind": job["kind"], "target_id": job["target_id"],
              "trigger": job["trigger"]}
    log.info("job_started", **fields)
    handler = handlers.get(job["kind"])
    try:
        if handler is None:
            raise JobError(f"Unknown job kind: {job['kind']}")
        stats = handler(job) or {}
        jobs.finish(job["id"], stats)
        log.info("job_succeeded", duration_ms=_ms(started), **fields, **stats)
    except JobError as err:
        jobs.fail(job["id"], str(err))
        log.error("job_failed", duration_ms=_ms(started), error=str(err), **fields)
    except Exception as err:
        message = _describe(err)
        jobs.fail(job["id"], message)
        log.exception("job_failed", duration_ms=_ms(started), error=message, **fields)


def _describe(err: Exception) -> str:
    """One line for the UI. For database errors only the driver's first line is
    used, so no SQL text or row data ends up in the job record."""
    origin = getattr(err, "orig", None)
    if origin is not None:
        first_line = (str(origin).strip().splitlines() or [""])[0]
        return f"Database error ({type(origin).__name__}): {first_line[:300]}"
    return f"{type(err).__name__}: {str(err)[:500]}"


def _ms(started: float) -> int:
    return int((time.monotonic() - started) * 1000)
