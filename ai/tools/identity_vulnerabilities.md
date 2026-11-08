---
name: identity_vulnerabilities
description: Vulnerabilities on the systems one account reaches, grouped by host, class-C subnet, port, plugin or CVE, sorted by severity, CVSS or VPR. Use after identity_access, or directly when the user asks what is wrong on the systems a person can reach.
---

```json parameters
{
  "type": "object",
  "properties": {
    "account": { "type": "string", "description": "sAMAccountName, UPN or DOMAIN\\name" },
    "group_by": { "type": "string", "enum": ["none", "host", "ip_block", "port", "plugin", "cve"], "description": "none lists findings; others return one row per group with counts. Default plugin." },
    "severity_min": { "type": "integer", "description": "0 info … 4 critical. Default 3 (high and critical)." },
    "sort": { "type": "string", "enum": ["severity", "cvss", "vpr", "count"], "description": "Default vpr. count applies to grouped results." },
    "exploitable_only": { "type": "boolean", "description": "Only findings with a known public exploit" },
    "limit": { "type": "integer", "description": "Rows to return, default 30, max 100" }
  },
  "required": ["account"]
}
```

**Returns** `scope` (account, hosts considered), `summary` (total, by_severity, exploitable) and
`rows`. Grouped rows carry `key`, `name`, `findings`, `hosts`, `critical`, `high`, `max_vpr`,
`max_cvss`, `exploitable`, `sample_hosts`; flat rows carry host_name, ip, port, protocol,
plugin_id, plugin_name, severity, vpr_score, cvss_v3, cve, exploit_available, first_seen.

**Implementation:** host set from `v_risk_access` (same resolution as `identity_access`), then
`vulnerabilities` through `ix_vulnerabilities_host_sev_vpr`. `ip_block` groups on
`set_masklen(network(ip), 24)`.
