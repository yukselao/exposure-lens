"""S5: services and the account each one runs as, from 44401."""
import re

from identity import principal

VERSION = 1
SECTION = re.compile(r"^The following services are set to start (\w+)", re.I)
HEAD = re.compile(r"^\s+(\S.*?) startup parameters\s*:\s*$")
FIELD = re.compile(r"^\s+(Display name|Service name|Log on as|Executable path)\s*:\s*(.*)$")


def parse(plugin_id, text, ctx):
    rows, current, start = [], None, None
    for line in text.split("\n"):
        m = SECTION.match(line)
        if m:
            start = m.group(1).lower()
            continue
        m = HEAD.match(line)
        if m:
            current = {"service_name": m.group(1), "start_type": start, "raw_account": None}
            rows.append(current)
            continue
        m = FIELD.match(line)
        if m and current is not None:
            key, value = m.group(1), m.group(2).strip()
            if key == "Display name":
                current["display_name"] = value
            elif key == "Service name":
                current["service_name"] = value or current["service_name"]
            elif key == "Log on as":
                current["raw_account"] = value
            else:
                current["path"] = value
    out = {}
    for row in rows:
        raw = row.pop("raw_account")
        row["principal"] = principal(raw, aliases=ctx.aliases, host_id=ctx.host_id) if raw else None
        out[row["service_name"]] = row
    return {"services": list(out.values())}
