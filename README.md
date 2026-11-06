# exposure-lens

Exposure Lens pulls data from Tenable Security Center and Tenable Identity Exposure into PostgreSQL and shows the two side by side in a web UI.

## Services

| Service | What it does |
| --- | --- |
| `postgres` | PostgreSQL 17, the data store. |
| `migrate` | Applies the Alembic revisions in `db/` and exits. The other services wait for it. |
| `tie` | [TIE connector](connectors/tie/README.md): serves Identity Exposure data as JSON over HTTP. |
| `tie_postgres` | [TIE to PostgreSQL connector](connectors/tie_postgres/README.md): mirrors AD objects from `tie` into PostgreSQL. |
| `sc` | [Security Center connector](connectors/sc/README.md): pulls cumulative vulnerabilities into PostgreSQL. |
| `php`, `web` | The web UI in `wwwroot/` (php-fpm behind nginx), on `http://127.0.0.1:8085`. |

Application code is bind-mounted into the containers. The images hold only runtimes and libraries, so a code change needs no rebuild. A Python connector reads its code at start; restart it after a change (`docker compose restart sc`).

## First run

```sh
cp .env.example .env                      # then set tie_host, tie_apikey and pg_password
mkdir -p secrets
openssl rand -hex 32 > secrets/tie_connector_token
openssl rand -hex 32 > secrets/app_secret_key
chmod 600 secrets/*
docker compose up -d --build
```

Open `http://127.0.0.1:8085`, then:

1. **Settings > Security Center:** enter the URL and API keys, run **Test connection**, add a pool, add a query to it, and press **Sync now**.
2. **Settings > Identity Exposure:** run **Test connection**, then **Sync now**.

`secrets/app_secret_key` encrypts the Security Center keys and the TIE token stored in PostgreSQL. If you replace it, enter those values again in Settings.

The UI has no login yet. It is published on `127.0.0.1` only; do not expose the port.

## How a sync runs

The UI and the connectors talk only through the `sync_jobs` table. The UI inserts a `queued` row. Each connector checks for work every 30 seconds (`*_poll_interval_seconds` in `.env`), runs the job, and records progress, result and errors on the row. A pool or the TIE sync with an interval above 0 is queued by the connector itself when it is due.

A sync mirrors the source: rows are upserted, and rows the source no longer returns are deleted.

## Logs

Each connector writes JSON lines to stdout and to `logs/<connector>/connector.log` (rotated at 10 MB, 5 files kept). The **Logs** link in the top bar shows, filters and downloads them. `docker compose logs sc` shows the same lines.

## Database

`db/eldb/models.py` defines the schema, and Alembic revisions in `db/alembic/versions/` version it. After changing a model:

```sh
docker compose run --rm --no-deps -v "$PWD/db:/dbrw" -w /dbrw -e PYTHONPATH=/dbrw \
  migrate alembic revision --autogenerate -m "describe the change"
docker compose up migrate
```

| Table or view | Content |
| --- | --- |
| `settings` | One JSON document per section (`seccenter`, `tie`, and what the connectors cache). |
| `sync_jobs` | The job queue and its history. |
| `sc_pools`, `sc_queries` | Pools and the queries in them. |
| `host_assets`, `vulnerabilities` | Hosts and cumulative findings from Security Center. |
| `sc_query_findings` | Which queries return each finding. |
| `ad_users`, `ad_groups`, `ad_computers`, `ad_group_policies` | AD objects from Identity Exposure. Every attribute is in the `attributes` JSONB column. |
| `ad_tier_assignments` | Tier of an AD object, from TIE (`tie`) or set by hand (`manual`). |
| `v_ad_objects`, `v_ad_object_tier` | All AD objects in one relation; the tier that applies to each. |
| `v_host_assets`, `v_asset_correlation` | Hosts with their class (`device` or `iot`) and the AD computer they match by name. |

## Tests

```sh
docker compose run --rm --no-deps sc python -m pytest -q -p no:cacheprovider /app/tests
docker compose run --rm --no-deps tie_postgres python -m pytest -q -p no:cacheprovider /app/tests
```

## Not built yet

- Software inventory and local user discovery, which will be read from Security Center plugin output.
- The Risk and Reports sections (the menu entries exist).
- Login for the web UI.
