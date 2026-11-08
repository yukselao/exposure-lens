---
name: find_host
description: Find hosts by name, IP address or CIDR block (e.g. 192.168.1.0/24). Returns each host with its risk summary. Call first when the user names a system or a network.
---

```json parameters
{
  "type": "object",
  "properties": {
    "query": { "type": "string", "description": "Host name fragment, IP address or CIDR" },
    "sort": { "type": "string", "enum": ["critical", "vpr", "acr", "name"], "description": "Default critical" },
    "limit": { "type": "integer", "description": "Rows to return, default 25, max 100" }
  },
  "required": ["query"]
}
```

**Returns** `total` and `rows` (host_asset_id, host_name, ip, os, asset_class, domain_joined,
ad_matched, host_tier, acr_score, aes_score, critical, high, medium, max_vpr, exploitable,
credentialed, last_seen).

**Implementation:** `host_risk`; CIDR and exact IP use `CAST(ip AS inet) <<= :cidr` through
`ix_host_risk_ip_inet`, names use the trigram index `ix_host_risk_search_trgm`.
