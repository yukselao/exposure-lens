# Tenable Identity Exposure connector

The connector serves Tenable Identity Exposure data and analysis over HTTP as JSON. It vendors the TypeScript tool engine from `TIE_MCP` commit `c9721f1` under `src/`, with its MIT license. The HTTP entry point and cache lifecycle live in `src/index.ts` and `src/cache.ts`.

## Tool access

The ten existing custom tools and `refresh_ad_cache` are enabled by default. To enable generated tools, set `tie_enabled_tools` in `.env` to a comma-separated list of names, for example `get_about,list_directories`. Unknown names fail startup.

Call `POST /v1/tools/<tool_name>` with a JSON object containing the arguments. For tools without arguments, send `{}`. Call `GET /v1/tools` once the cache is ready to get the enabled tools and their input schemas.

The [OpenAPI specification](openapi.json) describes the HTTP routes, default tools, arguments, and freshness metadata. Run `npm run spec` from this directory after changing tool schemas.

## Docker Compose configuration

From the project root, copy `.env.example` to `.env` and fill in the settings below. Save a generated bearer token, such as the output of `openssl rand -hex 32`, in `./secrets/tie_connector_token`. The Compose mount needs to be readable by the container's unprivileged user. On Linux, one option is to set the secret directory to mode `0700` and the token file to `0444`, so the parent directory restricts host access and the mounted file is readable in the container.

Run `docker compose up --build -d`. Build time uses public registries for base images and npm packages. The connector and database listen on the Compose network, and callers use `http://tie:8080`. The connector needs network access to the configured TIE host.

| Compose setting | Used by | Purpose |
| --- | --- | --- |
| `tie_host` | TIE connector | Full upstream URL, such as `https://tie.example.internal`. Maps to `TIE_BASE_URL`. |
| `tie_apikey` | TIE connector | Upstream Tenable API key. Maps to `TIE_API_KEY`. |
| `tie_connector_token_file` | TIE connector | Path on the Compose host to the bearer token file. Defaults to `./secrets/tie_connector_token`. |
| `tie_enabled_tools` | TIE connector | Comma-separated generated tool names to enable. Defaults to empty. |
| `tie_cache_refresh_interval_minutes` | TIE connector | Minutes between the completion of one AD scan and the start of the next. Defaults to `0` to disable scheduling. |
| `pg_user` | PostgreSQL | Database user. Maps to `POSTGRES_USER`. |
| `pg_password` | PostgreSQL | Database password. Maps to `POSTGRES_PASSWORD`. |
| `pg_db` | PostgreSQL | Database name. Maps to `POSTGRES_DB`. |

PostgreSQL settings configure the data lake service. The TIE connector serves JSON over HTTP independently.

## Connector authentication

Send `Authorization: Bearer <token>` on every request. Compose mounts this dedicated token as a Docker secret. Keep it separate from the upstream `TIE_API_KEY`, which the connector uses to authenticate to Tenable. Missing or invalid connector tokens return HTTP `401 Unauthorized`.

If TIE uses an internal CA, mount the CA certificate into the TIE connector container and set `NODE_EXTRA_CA_CERTS` to the mounted file's path in a Compose override.

## Startup and refresh

The connector starts a full AD object scan at startup and consolidates the records into one searchable snapshot. During startup and manual or expiry-triggered refreshes, requests to run or list enabled tools return HTTP `503 Service Unavailable` with JSON status and a `Retry-After` header. Poll `GET /v1/status` for the current state. It returns HTTP `200` in every cache state.

Example progress response during a startup scan:

```json
{
  "state": "warming",
  "phase": "scanning",
  "trigger": "startup",
  "pagesFetched": 18,
  "objectsFetched": 16420,
  "elapsedMs": 12500,
  "objectCount": null,
  "nextRefreshAt": null,
  "freshness": {"adSnapshot": null, "refreshInProgress": true}
}
```

Scan counters describe work completed so far. `objectCount` is the size of the last completed consolidated snapshot. A failed scan reports `failed` and can be retried through `refresh_ad_cache`. With scheduling disabled, the next tool request starts a refresh when the snapshot expires. The default lifetime is one day. The connector container supports `TIE_CACHE_TTL_MS`, `TIE_TIMEOUT`, `TIE_WARM_CONCURRENCY`, and `TIE_WARM_CHUNK` as environment overrides.

Set `tie_cache_refresh_interval_minutes=60` in `.env` and recreate the connector to enable hourly refreshes. The scheduler uses the configured interval after each attempt finishes, including failed scans. During a scheduled refresh, tools continue to use the previous snapshot and report `freshness.refreshInProgress: true`. The new snapshot replaces the previous one only after successful consolidation. If a scheduled scan fails, the previous snapshot stays available and the status endpoint reports the failure and next retry time. Scheduled and manual refreshes share a single scan. With scheduling enabled, the interval controls when scans run and the TTL controls when freshness metadata marks the data stale.

The old and new snapshots can overlap in memory during refresh. Size the container and Node heap for that peak when deploying to a large directory.

`POST /v1/tools/refresh_ad_cache` takes an empty JSON object and returns HTTP `202 Accepted` with progress for a fresh or already active scan. A successful refresh replaces the searchable snapshot and invalidates its control graph. Graph tools rebuild it on demand. The profile-specific deviance index has its own refresh controls.

## Tools and parameters

| Tool | Required arguments | Optional arguments | Result |
| --- | --- | --- | --- |
| `get_topology` | None | None | Forests, domains, and their infrastructure and directory IDs. |
| `get_preferred_profile` | None | None | Preferred TIE profile ID and name for the configured account. |
| `query_ad_objects` | None | `expression` (string), `limit` (integer, default `50`, `0` means all matches), `refresh` (boolean, default `false`) | Matching objects, total matches, returned count, truncation flag, and snapshot metadata. |
| `get_ad_object` | Exactly one of `distinguishedName`, `sid`, or `samAccountName` (strings), or `liveData: true` with both numeric `directoryId` and `id` | `liveData`, `decodeSecurityDescriptor`, `refresh` (booleans, default `false`) | One object and optionally its decoded permissions. |
| `get_blast_radius` | `principal` (string) | `maxDepth`, `maxNodes` | Objects the principal can reach and the shortest control path to each. |
| `get_control_paths` | `from`, `to` (strings) | `maxDepth` | Shortest control path from the source to the target, if found. |
| `get_asset_exposure` | None | `targets` (array of strings), `tier0` (boolean), `maxDepth`, `maxNodes` | Principals that can reach the targets. An empty or omitted `targets` uses the built-in Tier-0 groups. With explicit targets, `tier0: true` adds those groups. |
| `get_tier0` | None | `maxDepth`, `maxNodes` | Built-in Tier-0 groups and principals that can reach them, with escalation paths. |
| `get_identity_360` | Exactly one of `distinguishedName`, `sid`, `samAccountName` (strings), or `objectId` (integer) | Identity 360 options below | Detailed TIE findings where the identity is the target, a risky trustee, or inherits the exposure. |
| `get_identity_360_summary` | `identities` (array of DN, SID, or SAM account name strings, or integer TIE object IDs) | Identity 360 options below | Counts by severity and exposure layer for each identity. |
| `refresh_ad_cache` | None | None | Progress for a fresh or already active AD snapshot scan. New connector operation. |

Graph tools accept a SID, distinguished name (DN), or SAM account name for principal and target references. `maxDepth` and `maxNodes` are positive integers and default to `6` and `2000`, respectively. Graph tools can build the control graph on first use.

On a ready connector, sending `refresh: true` to `query_ad_objects` or `get_ad_object` also starts a background scan and returns `202`. After the cache is ready, repeat the query with the flag omitted to get the data.

### Live object details and freshness

Use `{"samAccountName":"analyst","liveData":true}` to resolve an object's IDs from the snapshot and fetch its attributes from Tenable. Use `{"directoryId":3,"id":90266,"liveData":true}` to query an object by its numeric TIE record ID, including one absent from the snapshot. The separate `objectId` string identifies the directory and AD GUID. `liveData: true` and `refresh: true` are mutually exclusive.

A named lookup that cannot be resolved returns `found: false` with `reason: "not_found_in_snapshot"`. A Tenable `404` returns `found: false` with `reason: "not_found_in_tie"`. Other upstream failures return HTTP `502`. A live lookup leaves the snapshot unchanged. If you request decoded permissions, the descriptor comes from the live object and trustee and schema names come from the snapshot.

Tool results include a top-level `freshness` object:

| Field | Meaning |
| --- | --- |
| `adSnapshot` | Timing for the AD snapshot used by the result, including graph results and any cached identity lookup or permission-name resolution used by a live object lookup. |
| `devianceIndex` | Timing for the separate profile-specific deviance scan used by Identity 360. |
| `object` | `source: "tie_live"` and `fetchedAt` for live object details and Tenable `404` results. |
| `upstream` | `source: "tie_live"` and `fetchedAt` for discovery and enabled generated tools. |
| `refreshInProgress` | Whether an AD scan is in progress when the connector sends the response. |

Cached sources include ISO timestamps `scanStartedAt` and `scanCompletedAt`, milliseconds since completion in `ageMs`, and a `stale` flag based on the cache TTL. Live timestamps describe retrieval from Tenable. Generated tools that return arrays or scalars place the upstream payload under `data`.

### Identity 360 options

| Argument | Type | Default and behavior |
| --- | --- | --- |
| `profileId` | Integer | Uses the configured account's preferred profile when omitted. |
| `includeTarget` | Boolean | `true`. Include findings filed on the identity. |
| `includeTrustee` | Boolean | `true`. Include findings where the identity is a risky trustee. |
| `includeInherited` | Boolean | `true`. Include findings inherited from containing AD objects. This can build the control graph. |
| `minSeverityBand` | String | Accepts `Critical`, `High`, `Medium`, or `Low`. When omitted, includes all severity bands. |
| `includeResolved` | Boolean | `false`. Include resolved findings when enabled. |
| `includeIgnored` | Boolean | `false`. Include ignored findings when enabled. |
| `includeDisabledCheckers` | String | `exclude` by default. Also accepts `flag` or `include`. |
| `refresh` | Boolean | `false`. Forces a rescan of the profile's deviance index when enabled. |

### Querying AD objects

Omit the `expression` argument to match every object in the consolidated snapshot. A request of `{}` returns up to 50 objects and the full count. A request of `{"limit":0}` returns the entire consolidated snapshot in one response. An unrestricted response can be large.

If enabled, the generated `list_ad_objects` tool returns the first raw page from TIE. Use `query_ad_objects` for a full pull of the consolidated snapshot.

These JSON bodies select specific object classes:

| Object type | Request body |
| --- | --- |
| User accounts, excluding computers | `{"expression":"objectClass=user AND NOT objectClass=computer"}` |
| Computers | `{"expression":"objectClass=computer"}` |
| Organizational units | `{"expression":"objectClass=organizationalUnit"}` |
| Groups | `{"expression":"objectClass=group"}` |
| Group Policy Objects (AD containers) | `{"expression":"objectClass=groupPolicyContainer"}` |

Add `"limit":0` to return all matches. `objectClass` can have several values. The `=` operator matches an individual class exactly, and the user filter excludes computers because their class list can also contain `user`. Names and values are case-insensitive. The `type` field describes the TIE record type, such as `LDAP` or `SYSVOL`.
