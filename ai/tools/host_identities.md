---
name: host_identities
description: Who touches one host - accounts with local admin, RDP or PowerShell rights, sessions and logons seen, services and the accounts they run as, plus the host's local accounts. Use for blast-radius and lateral-movement questions.
---

```json parameters
{
  "type": "object",
  "properties": {
    "target": { "type": "string", "description": "Host name or IP address of one host" },
    "limit": { "type": "integer", "description": "Rows per section, default 40, max 150" }
  },
  "required": ["target"]
}
```

**Returns** `host` (host_risk row), `access` (identity_name, kind, tier, access_type,
access_class, via_group, evidence, last_seen, other_hosts = how many other hosts that identity
reaches), `local_accounts` (name, rid, account_type, disabled, privileged, groups, flags) and
`services` (service_name, display_name, run_as, start_type).

**Implementation:** `v_risk_access` by `host_asset_id`, `local_accounts`, `host_services`
joined to `principals`. Scanner logins are excluded.
