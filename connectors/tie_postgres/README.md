# TIE to PostgreSQL connector

A Python worker that mirrors Active Directory objects from the [TIE connector](../tie/README.md) into PostgreSQL. Every `POLL_INTERVAL_SECONDS` (30 by default) it checks the `sync_jobs` table for work.

It reads only from the TIE connector's web service (`GET /v1/status`, `POST /v1/tools/query_ad_objects`, `POST /v1/tools/get_tier0`). It does not talk to Tenable.

## Settings

Under **Settings > Identity Exposure** in the web UI:

| Setting | Default |
| --- | --- |
| Connector URL | `http://tie:8080` (`TIE_CONNECTOR_URL`) |
| Connector token | The Docker secret `tie_connector_token`, shared with the TIE connector |
| Sync every (minutes) | 0, which means manual sync only |

## What a sync does

1. Waits until the TIE connector's AD snapshot is ready.
2. Pulls each object class in full (`limit: 0`) with the expressions from the TIE connector README.
3. Decodes the attributes. TIE sends every value as a JSON-encoded string.
4. Upserts the rows, keyed by `directory_id` and the TIE object `id`.
5. Deletes the rows TIE no longer has.
6. Replaces the Tier 0 assignments with the result of `get_tier0`.

| Table | Objects |
| --- | --- |
| `ad_users` | Users. `account_type` is `user`, `service` (has an SPN) or `gmsa` (managed service account). |
| `ad_computers` | Computers, without managed service accounts. |
| `ad_groups` | Groups. |
| `ad_group_policies` | Group Policy containers. |

Key fields are columns. Every attribute TIE returned is in the `attributes` JSONB column under its lower-case name, for example `attributes->>'useraccountcontrol'`. Password analysis flags from TIE are under `attributes->'_derived'`.

The whole sync is one transaction, so a failure leaves the tables as they were. The delete step is skipped when a class came back incomplete or TIE returned no objects at all.

## Tiers

`ad_tier_assignments` holds two kinds of rows:

- `source = 'tie'`: Tier 0 from the TIE control graph. Replaced on every sync.
- `source = 'manual'`: set in the UI under **Explore > AD Objects**. A sync never changes these, and they take precedence. A manual row with no tier removes the object from every tier.

`v_ad_object_tier` gives the tier that applies to each object.

## Logs

JSON lines on stdout and in `logs/tie_postgres/connector.log`. Main events: `job_started`, `tie_waiting_for_snapshot`, `tie_snapshot_ready`, `tie_class_synced`, `tie_objects_deleted`, `tie_delete_skipped`, `tie_tier0_synced`, `job_succeeded`, `job_failed`. Tokens and AD records are never logged.
