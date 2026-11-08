"""S4: who is or was logged on, from 161502, 38689, 92373 and 11459."""
import re

from identity import principal

VERSION = 1
LOGGED_ON = re.compile(r"-\s*(S-1-[\d-]+)\s*\n\s*Domain\s*:[ \t]*(.*?)[ \t]*\n\s*Username\s*:[ \t]*(.*?)[ \t]*(?:\n|$)")


def parse(plugin_id, text, ctx):
    rows = []
    if plugin_id == 161502:
        for sid, domain, name in LOGGED_ON.findall(text):
            who = principal(domain=domain, name=name, sid=sid, aliases=ctx.aliases, host_id=ctx.host_id)
            if who:
                rows.append({"principal": who, "session_type": "session"})
    elif plugin_id in (38689, 11459):
        m = re.search(r"(?:Last Successful logon|Last user)\s*:\s*(\S.*)", text, re.I)
        raw = m.group(1).strip() if m else ""
        who = principal(raw, aliases=ctx.aliases, host_id=ctx.host_id) if raw else None
        if who:
            rows.append({"principal": who, "session_type": "last_logon"})
    elif plugin_id == 92373:
        for line in text.split("\n"):
            line = line.strip()
            if not line or line.startswith("Extended SMB session"):
                continue
            who = principal(line, aliases=ctx.aliases, host_id=ctx.host_id)
            if who:
                rows.append({"principal": who, "session_type": "smb_session"})
    return {"sessions": rows}
