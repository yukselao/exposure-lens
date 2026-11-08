---
name: host_vulnerabilities
description: Vulnerabilities of one host, one IP or a whole CIDR block, grouped by host, class-C subnet, port, plugin or CVE and sorted by severity, CVSS or VPR.
---

```json parameters
{
  "type": "object",
  "properties": {
    "target": { "type": "string", "description": "Host name, IP address or CIDR (10.0.0.0/24)" },
    "group_by": { "type": "string", "enum": ["none", "host", "ip_block", "port", "plugin", "cve"], "description": "Default none for one host, plugin for a CIDR." },
    "severity_min": { "type": "integer", "description": "0 info … 4 critical. Default 2 for one host, 3 for a CIDR." },
    "sort": { "type": "string", "enum": ["severity", "cvss", "vpr", "count"], "description": "Default vpr" },
    "exploitable_only": { "type": "boolean" },
    "limit": { "type": "integer", "description": "Default 30, max 100" }
  },
  "required": ["target"]
}
```

**Returns** `scope` (target, hosts matched), `summary` (total, by_severity, exploitable) and
`rows` in the same shape as `identity_vulnerabilities`.

**Implementation:** host set from `host_risk` (name via `lower(host_name)`, IP/CIDR via
`ix_host_risk_ip_inet`), findings through `ix_vulnerabilities_host_sev_vpr`.
