---
name: group_members
description: Members of an Active Directory group by name (direct members, with nested groups listed as groups), each with tier, enabled state and admin_count. Also returns the groups this group is nested in.
---

```json parameters
{
  "type": "object",
  "properties": {
    "group": { "type": "string", "description": "Group name or sAMAccountName, e.g. 'Domain Admins'" },
    "limit": { "type": "integer", "description": "Default 60, max 300" }
  },
  "required": ["group"]
}
```

**Returns** `group` (name, sam, tier, group_type, member_count, description, directory),
`members` (name, sam_account_name, object_type, enabled, tier, admin_count, last_logon,
pwd_last_set) and `member_of` (parent groups with tier).

**Implementation:** `ad_groups` by `lower(name)` / `lower(sam_account_name)`, fallback to the
trigram index; `ad_group_members` on `group_key` joined to `v_ad_objects` and `v_ad_object_tier`.
