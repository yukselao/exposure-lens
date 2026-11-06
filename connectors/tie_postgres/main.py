"""TIE -> PostgreSQL connector: checks its jobs every POLL_INTERVAL_SECONDS and
mirrors AD objects from connectors/tie into ad_users, ad_groups, ad_computers
and ad_group_policies."""
from elcommon import jsonlog, worker

import sync

CONNECTOR = "tie_postgres"


def main():
    log = jsonlog.setup(CONNECTOR)
    worker.run(
        CONNECTOR, log,
        handlers={
            "sync": lambda job: sync.sync_ad(job, log),
            "test_connection": lambda job: sync.test_connection(job, log),
        },
        schedule=lambda: sync.enqueue_if_due(log),
    )


if __name__ == "__main__":
    main()
