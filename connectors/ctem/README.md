# CTEM worker

Turns stored plugin output into scenario tables, matches the identities in it to Active Directory, and builds the data behind the Risk screen. It reads and writes only PostgreSQL. Every `POLL_INTERVAL_SECONDS` (30 by default) it checks `sync_jobs`.

## Pipeline

| Job | Runs in | When | What it does |
| --- | --- | --- | --- |
| `plugin_collect` | `sc` | Every 6 hours (Settings > CTEM Pipeline), or Run now | Pulls the selected plugins into `plugin_outputs`, looks in recent scan results for full text and attachments, then queues `extract` |
| `extract` | `ctem` | After `plugin_collect`, and every 15 minutes | Parses outputs whose hash or extractor version changed, then queues `correlate` |
| `correlate` | `ctem` | After `extract`, after every SC and TIE sync, and every hour | Matches identities to AD, rebuilds `identity_host_access` and `attack_edges`, refreshes `host_risk` |
| `rebuild` | `ctem` | Run now only | Parses every stored output again |

## Extractors

`extractors/` holds one module per scenario. `parse(plugin_id, text, ctx)` returns a dict of record lists; `extract.py` writes them to the scenario table and to `host_iocs`. Rows of an output that it no longer produces are deleted.

| Scenario | Module | Plugins | Tables |
| --- | --- | --- | --- |
| S1 | `software` | 20811, 22869, 83991, 178102 | `software`, `host_software` |
| S2 | `local_groups` | 71246, 10901–10906 | `host_group_members` |
| S3 | `local_accounts` | 10860, 72684, 95928, 10913–10916, 83303 | `host_local_accounts` |
| S4 | `sessions` | 161502, 38689, 92373, 11459 | `host_sessions` |
| S5 | `services` | 44401 | `host_services` |
| S11 | `scan_coverage` | 19506, 141118, 110385, 104410, 110723 | `host_scan_coverage` |
| S12 | `host_identity` | 170631, 170396, 213471, 55472, 12053, 10150, 10785 | `host_identities` |
| S15 | `browser_extensions` | 96533, 96534, 176212 and the other browser plugins | `browser_extensions`, `host_browser_extensions` |

To add a parser: write the module against real output, save samples under `tests/fixtures`, register it in `extractors/__init__.py`, and set `extractor` for its plugins in `plugin_catalog`. Bump `VERSION` in a module when its output changes; its plugins are parsed again on the next `extract`.

## Identity matching

`identity.py` normalises account text into one `principals` row, so `LABNET\x`, `labnet.local\x` and `x@labnet.local` are one identity. `correlate.py` then fills `ad_object_key`, in this order: SID, domain and account name (through `ad_domains`), UPN, and a bare account name looked up in the domain of the host it was seen on. A bare name on a host with no known domain stays unmatched and shows as unverified, because a match across all directories could tie it to an unrelated account and a wrong tier.

## Troubleshooting

- **Settings > CTEM Pipeline** shows each job and, per plugin, how many outputs are collected, pending, parsed, failed, cut off or pointing to a file.
- **Logs > CTEM worker** shows `extract_batch`, `extract_failed`, `principals_resolved` and `edges_built`. `sc` logs `plugin_collect_page`, `attachment_fetched` and `attachment_skipped`.
- A parse error marks that output `failed` and the run carries on. The error is on the settings page and in `plugin_outputs.extract_error`.
- To see what an extractor makes of one output, without writing anything:

  ```sh
  docker compose run --rm ctem python /app/cli.py extract --output-id 123
  ```

## Tests

```sh
docker compose run --rm --no-deps ctem python -m pytest -q -p no:cacheprovider /app/tests
```
