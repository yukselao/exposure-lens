"""S11: whether a host was scanned with credentials, from 19506, 141118 and the failure plugins."""
import re
from datetime import datetime, timezone

from identity import principal

VERSION = 2
STATUS = {141118: "credentialed", 110385: "insufficient_privilege", 104410: "failed", 110723: "no_credentials"}
LOGIN = re.compile(r"User:\s+'(.*?)'\s*\nPort:\s*(\d+)\s*\nProto:\s*(\S+)", re.M)


def _scan_info(text):
    fields = dict((k.strip().lower(), v.strip()) for k, v in re.findall(r"^([A-Za-z][A-Za-z ()-]*?)\s*:\s*(.*)$", text, re.M))
    when = None
    start = fields.get("scan start date")
    if start:
        for form in ("%Y/%m/%d %H:%M %Z", "%Y/%m/%d %H:%M"):
            try:
                when = datetime.strptime(start.rsplit(" ", 1)[0] if form.endswith("%M") else start, form).replace(tzinfo=timezone.utc)
                break
            except ValueError:
                continue
    credentialed = fields.get("credentialed checks", "")
    status = "credentialed" if credentialed.lower().startswith("yes") else "no_credentials"
    return {"status": status, "protocol": "", "scan_name": fields.get("scan name"), "scan_time": when, "principal": None,
            "detail": {"credentialed_checks": credentialed, "policy": fields.get("scan policy used"),
                       "scanner_ip": fields.get("scanner ip")}}


def parse(plugin_id, text, ctx):
    if plugin_id == 19506:
        return {"scan_coverage": [_scan_info(text)]}
    rows = {}
    for account, port, protocol in LOGIN.findall(text):
        who = principal(account, aliases=ctx.aliases, host_id=ctx.host_id)
        rows[protocol.upper()] = {"status": STATUS[plugin_id], "protocol": protocol.upper(), "principal": who,
                                  "scan_name": None, "scan_time": None, "detail": {"port": int(port)}}
    if not rows and text.strip():
        rows[""] = {"status": STATUS[plugin_id], "protocol": "", "principal": None, "scan_name": None,
                    "scan_time": None, "detail": {}}
    return {"scan_coverage": list(rows.values())}
