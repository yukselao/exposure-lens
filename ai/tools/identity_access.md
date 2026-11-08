---
name: identity_access
description: The systems one account reaches or is used on - local admin rights, RDP/PowerShell rights, sessions, logons, services it runs - each with the host's risk (critical/high counts, max VPR, ACR, tier).
---

```json parameters
{
  "type": "object",
  "properties": {
    "account": { "type": "string", "description": "sAMAccountName, UPN or DOMAIN\\name" },
    "access_class": { "type": "string", "enum": ["any", "right", "usage"], "description": "right = can log on (local_admin, rdp_right, psremote_right); usage = was seen (session, last_logon, smb_session, service_account). Default any." },
    "limit": { "type": "integer", "description": "Rows to return, default 50, max 200" }
  },
  "required": ["account"]
}
```

**Returns** `summary` (hosts, hosts_with_critical, hosts_high_acr, by_access_type) and `rows`
(host_name, ip, os, access_type, access_class, via_group, evidence, confidence, last_seen,
critical, high, max_vpr, acr_score, host_tier, host_asset_id).

**Implementation:** `v_risk_access` filtered by `ad_object_key` (when the account is in AD) or
by `lower(identity_name)` / the short name expression index when it is only in telemetry. The
scanner's own login (`scan_account`) is excluded.
