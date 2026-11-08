"""CTEM worker: turns plugin outputs into scenario tables, matches identities to
AD and builds the data behind the Risk screen."""
from elcommon import jobs, jsonlog, worker

import correlate
import extract

CONNECTOR = "ctem"
EXTRACT_EVERY = 15      # minutes
CORRELATE_EVERY = 60


def schedule(log):
    for kind, every in (("extract", EXTRACT_EVERY), ("correlate", CORRELATE_EVERY)):
        elapsed = jobs.minutes_since_last(CONNECTOR, kind, None)
        if (elapsed is None or elapsed >= every) and jobs.enqueue_if_idle(CONNECTOR, kind, None, "schedule"):
            log.debug("ctem_job_scheduled", kind=kind)


def main():
    log = jsonlog.setup(CONNECTOR)
    worker.run(
        CONNECTOR, log,
        handlers={
            "extract": lambda job: extract.run(job, log),
            "rebuild": lambda job: extract.run(job, log, rebuild=True),
            "correlate": lambda job: correlate.run(job, log),
        },
        schedule=lambda: schedule(log),
    )


if __name__ == "__main__":
    main()
