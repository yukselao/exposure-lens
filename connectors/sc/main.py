"""Security Center connector: checks its jobs every POLL_INTERVAL_SECONDS and
writes cumulative vulnerabilities to PostgreSQL (vulnerabilities, host_assets)."""
from elcommon import jsonlog, worker

import collect
import sync

CONNECTOR = "sc"


def main():
    log = jsonlog.setup(CONNECTOR)
    worker.run(
        CONNECTOR, log,
        handlers={
            "sync": lambda job: sync.sync_pool(job, log),
            "test_connection": lambda job: sync.test_connection(job, log),
            "plugin_collect": lambda job: collect.plugin_collect(job, log),
        },
        schedule=lambda: (sync.enqueue_due_pools(log), collect.enqueue_if_due(log)),
    )


if __name__ == "__main__":
    main()
