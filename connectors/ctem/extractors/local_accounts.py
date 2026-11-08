"""S3: local accounts from 10860, 72684, 95928 and the flag lists (10913...)."""
import re

from identity import principal

VERSION = 1
FLAGS = {10913: "disabled", 10914: "password_never_changed", 10915: "never_logged_in",
         10916: "password_never_expires", 83303: "password_never_expires"}
RID_LINE = re.compile(r"^\s*-\s+(.+?)\s+\(id (\d+)", re.M)
LIST_LINE = re.compile(r"^\s*-\s+(\S.*?)\s*$", re.M)


def _local(name, ctx, sid=None):
    return principal(domain=".", name=name, sid=sid, aliases=ctx.aliases, host_id=ctx.host_id)


def _rid_list(text, ctx):
    rows = []
    for name, rid in RID_LINE.findall(text):
        who = _local(name, ctx)
        if who:
            rows.append({"principal": who, "rid": int(rid)})
    return rows


def _wmi(text, ctx):
    rows = []
    for block in re.split(r"\n\s*\n", text):
        fields = dict((k.strip().lower(), v.strip()) for k, v in re.findall(r"^\s*([A-Za-z ]+?)\s*:[ \t]*(.*)$", block, re.M))
        if not fields.get("name") or fields.get("source", "").lower() != "local":
            continue
        who = _local(fields["name"], ctx, fields.get("sid"))
        sid = fields.get("sid", "")
        if who:
            rows.append({"principal": who, "rid": int(sid.rsplit("-", 1)[1]) if re.search(r"-\d+$", sid) else None,
                         "disabled": fields.get("disabled", "").lower() == "true"})
    return rows


def _linux(text, ctx):
    rows = []
    account_type = "user"
    current = None
    in_groups = False
    for line in text.split("\n"):
        if "[ System Accounts ]" in line:
            account_type = "system"
            continue
        if "[ User Accounts ]" in line:
            account_type = "user"
            continue
        m = re.match(r"^User\s*:\s*(.+?)\s*$", line)
        if m:
            who = _local(m.group(1), ctx)
            current = {"principal": who, "account_type": account_type, "groups": []} if who else None
            if current:
                rows.append(current)
            in_groups = False
            continue
        if current is None:
            continue
        m = re.match(r"^Start script\s*:\s*(.*)$", line)
        if m:
            current["shell"] = m.group(1).strip()
            in_groups = False
            continue
        m = re.match(r"^Groups\s*:\s*(.*)$", line)
        if m:
            in_groups = True
            if m.group(1).strip():
                current["groups"].append(m.group(1).strip())
            continue
        if in_groups and line.startswith(" ") and line.strip():
            current["groups"].append(line.strip())
        elif not line.strip():
            in_groups = False
    return rows


def parse(plugin_id, text, ctx):
    if plugin_id == 10860:
        return {"local_accounts": _rid_list(text, ctx)}
    if plugin_id == 72684:
        return {"local_accounts": _wmi(text, ctx)}
    if plugin_id == 95928:
        return {"local_accounts": _linux(text, ctx)}
    flag = FLAGS[plugin_id]
    body = text.split("Note that", 1)[0]
    rows = []
    for name in LIST_LINE.findall(body):
        who = _local(name, ctx)
        if who:
            row = {"principal": who, "flags": {flag: True}}
            if flag == "disabled":
                row["disabled"] = True
            rows.append(row)
    return {"local_accounts": rows}
