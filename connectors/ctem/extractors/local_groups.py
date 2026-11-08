"""S2: local group membership from 71246 and the per-group lists (10902...)."""
import re

from identity import principal

VERSION = 1
GROUP_SIDS = {10902: ("Administrators", "S-1-5-32-544"), 10904: ("Backup Operators", "S-1-5-32-551"),
              10901: ("Account Operators", "S-1-5-32-548"), 10903: ("Server Operators", "S-1-5-32-549"),
              10905: ("Print Operators", "S-1-5-32-550"), 10906: ("Replicator", "S-1-5-32-552")}
KINDS = {"win32_group": "group", "win32_useraccount": "user", "win32_systemaccount": "user"}
MEMBER = re.compile(r"Name\s*:\s*(.*?)[ \t]*\n\s*Domain\s*:[ \t]*(.*?)[ \t]*\n\s*Class\s*:[ \t]*(\S+)[ \t]*\n\s*SID\s*:[ \t]*(\S*)")
LIST_MEMBER = re.compile(r"^\s*-\s+(.+?)\s+\((User|Group)\)\s*$", re.M)


def _wmi(text, ctx):
    rows = []
    for block in re.split(r"\n(?=Group Name\s*:)", text):
        head = re.match(r"\s*Group Name\s*:\s*(.*?)\s*\nHost Name\s*:\s*(.*?)\s*\nGroup SID\s*:[ \t]*(\S*)", block)
        if not head:
            continue
        group, host, group_sid = head.groups()
        aliases = ctx.aliases | {host}
        for name, domain, klass, sid in MEMBER.findall(block):
            who = principal(domain=domain, name=name, sid=sid, kind=KINDS.get(klass.lower()), aliases=aliases, host_id=ctx.host_id)
            if who:
                rows.append({"group_name": group, "group_sid": group_sid or None, "principal": who})
    return rows


def parse(plugin_id, text, ctx):
    if plugin_id == 71246:
        return {"group_members": _wmi(text, ctx)}
    if plugin_id == 60019:
        return {"group_members": []}
    group, group_sid = GROUP_SIDS[plugin_id]
    rows = []
    for raw, kind in LIST_MEMBER.findall(text):
        who = principal(raw, kind=kind.lower(), aliases=ctx.aliases, host_id=ctx.host_id)
        if who:
            rows.append({"group_name": group, "group_sid": group_sid, "principal": who})
    return {"group_members": rows}
