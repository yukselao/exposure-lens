---
name: risk_overview
description: The current state of the environment - counts for the four risk questions, severity totals, the most exposed hosts, scan coverage and sync times. Call when the question is broad ("how are we doing", "what should we fix first") or to orient before drilling down.
---

```json parameters
{
  "type": "object",
  "properties": {
    "top": { "type": "integer", "description": "How many top hosts to return, default 10, max 30" }
  }
}
```

**Returns** `risk` (users_reaching_critical, users_reaching_high_acr, hosts_with_tier0_usage,
service_accounts_on_critical, acr_threshold), `findings` (by_severity, hosts, hosts_with_critical,
exploitable), `ad` (users, service_accounts, groups, computers, tier counts), `coverage`
(hosts scanned credentialed vs not, identities matched to AD), `top_hosts` (host_name, ip,
critical, max_vpr, acr_score, identities, privileged_identities), `last_sync`.

**Implementation:** `dashboard_summary`, `host_risk`, `v_risk_access` aggregates — all read
models refreshed by `ctem_correlate`.
