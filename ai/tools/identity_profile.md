---
name: identity_profile
description: Full Active Directory profile of one account by sAMAccountName or UPN - key attributes, userAccountControl flags, SPNs, tier, direct group memberships and the path that makes it privileged.
---

```json parameters
{
  "type": "object",
  "properties": {
    "account": { "type": "string", "description": "sAMAccountName (e.g. sccm) or UPN (sccm@corp.local). DOMAIN\\name is accepted." }
  },
  "required": ["account"]
}
```

**Returns** `account` (the same identity card as `identity_access.person`, plus identity fields, tier and tier_detail, selected attributes: description,
mail, admincount, pwdlastset, lastlogontimestamp, useraccountcontrol flags, serviceprincipalname,
msds-allowedtodelegateto, memberof count), `groups` (name, tier, group_type, member_count) and
`usage` (count of hosts by access_type from `identity_host_access`).

**Implementation:** `ad_users` by `lower(sam_account_name)` or `lower(user_principal_name)`;
groups through `ad_group_members.member_key = object_key` joined to `ad_groups` and
`v_ad_object_tier`.
