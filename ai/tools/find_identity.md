---
name: find_identity
description: Find accounts by name, surname, display name, sAMAccountName, UPN or e-mail. Returns AD users and, separately, identities seen only in host telemetry (DOMAIN\name). Call first when the user names a person.
---

```json parameters
{
  "type": "object",
  "properties": {
    "query": { "type": "string", "description": "Name, surname, 'name surname', account name, UPN or e-mail; partial text is fine" },
    "limit": { "type": "integer", "description": "Rows to return, default 20, max 50" }
  },
  "required": ["query"]
}
```

**Returns** `ad_users` (sam_account_name, display_name, user_principal_name, mail, enabled, tier,
admin_count, account_type, last_logon, pwd_last_set, directory) and `telemetry_identities`
(identity_name, kind, hosts) for names that appear on hosts but have no AD match.

**Implementation:** exact matches on `lower(sam_account_name)`, `lower(user_principal_name)`,
`lower(attributes->>'mail')` are tried first (btree indexes), then a contains-search on the
trigram index `ix_ad_users_search_trgm`. Telemetry comes from `identity_host_access` through
`ix_identity_host_access_short_name`.
