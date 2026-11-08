---
name: search_software
description: Where a product is installed - hosts and versions for a software name fragment (e.g. TeamViewer, AnyDesk, Java, Chrome), with each host's risk. Use for remote-access tooling, end-of-life software and version sprawl questions.
---

```json parameters
{
  "type": "object",
  "properties": {
    "name": { "type": "string", "description": "Part of the product name" },
    "limit": { "type": "integer", "description": "Default 40, max 150" }
  },
  "required": ["name"]
}
```

**Returns** `products` (name, versions, latest_version, hosts, last_installed) and `rows`
(host_name, ip, os, host_tier, acr_score, critical, product, version, installed_at, last_seen).

**Implementation:** `software_summary` and `software`/`host_software` on `name_norm LIKE`,
joined to `host_risk`.
