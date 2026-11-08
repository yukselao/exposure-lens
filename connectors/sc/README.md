# Security Center connector

A Python worker that pulls cumulative vulnerabilities from Tenable Security Center into PostgreSQL (`vulnerabilities`, `host_assets`). It has no HTTP interface: every `POLL_INTERVAL_SECONDS` (30 by default) it checks the `sync_jobs` table for work.

The Security Center URL and API keys, the pools and their queries are all entered in the web UI under **Settings > Security Center**. The keys are stored encrypted (`secrets/app_secret_key`).

## Jobs

| Kind | What it does |
| --- | --- |
| `test_connection` | Calls `GET /rest/currentUser`, then caches the repository and plugin family lists for the query editor (`settings` section `seccenter_meta`). |
| `sync` | Runs every enabled query of one pool (`target_id` is the pool id). |
| `plugin_collect` | Pulls the output of the plugins selected in `plugin_catalog` into `plugin_outputs`. See the [CTEM worker](../ctem/README.md). |

A pool with **Sync every** above 0 is queued by the connector when that many minutes have passed since its last job ended.

## How a sync writes data

- Each query is fetched in pages of 1000 with `POST /rest/analysis` (tool `vulndetails`, source `cumulative`).
- Hosts and findings are upserted. A host is keyed by repository and `hostUUID` (the IP address when the repository has no host UUID). A finding is keyed by host, plugin, port and protocol.
- Key fields are columns; the complete Security Center record is kept in `vulnerabilities.raw`.
- One query is one transaction. If a page fails after 4 attempts, or Security Center returns fewer records than it reported, nothing of that query is written and the job fails. The other queries of the pool still run.
- After a complete fetch, the findings the query no longer returns are unlinked. A finding that no query returns is deleted, and so is a host left without findings.

## Filters

A query stores filter parameters; `filters.py` turns them into Security Center filters. The formats were checked against a real Security Center:

| Parameter | Security Center filter | Value sent |
| --- | --- | --- |
| `severity` | `severity` | `"3,4"` |
| `ip_range` | `ip` | `"10.0.0.5,10.0.1.0/24,10.0.2.1-10.0.2.50"` |
| `name_contains` | `pluginName` | substring, case-insensitive |
| `plugin_ids` | `pluginID` | `"20811,22869"` |
| `vpr_min`, `vpr_max` | `vprScore` | `"9-10"` |
| `cvss_v3_min`, `cvss_v3_max` | `cvssV3BaseScore` | `"7-10"` |
| `acr_min`, `acr_max` | `assetCriticalityRating` | `"8-10"` |
| `families` | `family` | `[{"id": "11"}]` |
| `repositories` | `repository` | `[{"id": "6"}]` |
| `exploit_only` | `exploitAvailable` | `"true"` |

A full score range (0 to 10) is not sent: Security Center would then drop every finding that has no score.

## Security Center API calls

The connector only reads. Reference: <https://docs.tenable.com/security-center/api/index.htm>.

| Call | Used for |
| --- | --- |
| `GET /rest/currentUser` | Connection test. |
| `GET /rest/repository` | Repository list for the query editor. |
| `GET /rest/pluginFamily` | Plugin family list for the query editor. |
| `POST /rest/analysis` | Cumulative vulnerability details, and single scan results (`sourceType: individual`) for full text and attachments. |
| `GET /rest/scanResult` | Finished scan results. |
| `GET /rest/scanResult/{id}/attachment/{attachmentID}` | One attached file. |

A reply counts as failed when the HTTP status is not 200 or `error_code` in the body is not 0. Connection errors and HTTP 429 and 5xx are retried after 2, 5 and 15 seconds.

## Origin

The client, the analysis request and the filter builders are adapted from the `jira-test` project (`ui/fastapi/app/services/tenable_sc.py`). Changed here: upsert instead of delete-and-reinsert, retries, the `error_code` check, typed columns, and the `pluginID` and `repository` filters.

## Logs

JSON lines on stdout and in `logs/sc/connector.log`. Main events: `job_started`, `sc_query_started`, `sc_page_fetched`, `sc_request_retry`, `sc_query_synced`, `sc_query_failed`, `job_succeeded`, `job_failed`. Keys and finding data are never logged.
