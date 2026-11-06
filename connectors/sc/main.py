"""Security Center connector: checks its jobs every POLL_INTERVAL_SECONDS and
writes cumulative vulnerabilities to PostgreSQL (vulnerabilities, host_assets)."""
from elcommon import jsonlog, worker

import sync

CONNECTOR = "sc"


def main():
    log = jsonlog.setup(CONNECTOR)
    worker.run(
        CONNECTOR, log,
        handlers={
            "sync": lambda job: sync.sync_pool(job, log),
            "test_connection": lambda job: sync.test_connection(job, log),
        },
        schedule=lambda: sync.enqueue_due_pools(log),
    )


if __name__ == "__main__":
    main()
