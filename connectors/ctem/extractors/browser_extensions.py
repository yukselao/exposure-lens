"""S15: browser extensions per user profile (96533 and its siblings)."""
import hashlib
import re
from datetime import datetime, timezone

from identity import principal

VERSION = 1
USER = re.compile(r"^User\s*:\s*(.+?)\s*$")
BROWSER = re.compile(r"^\|-\s*Browser\s*:\s*(.+?)\s*$")
FIELD = re.compile(r"^\s{4}([A-Z][A-Za-z ]*?)\s*:\s*(.*)$")


def _date(value):
    text = re.sub(r"\s+", " ", (value or "").replace(".", "")).strip()
    try:
        return datetime.strptime(text, "%b %d, %Y at %H:%M:%S GMT").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def _extension_id(fields):
    if fields.get("id"):
        return fields["id"]
    path = fields.get("path", "").rstrip("\\/")
    store_id = re.search(r"(?<![a-p])[a-p]{32}(?![a-p])", path)
    if store_id:
        return store_id.group(0)
    tail = re.split(r"[\\/]", path)[-1] if path else ""
    return tail or "name:" + hashlib.sha1(fields.get("name", "").encode()).hexdigest()[:16]


def parse(plugin_id, text, ctx):
    rows, user, browser, fields = [], None, None, {}

    def flush():
        nonlocal fields
        if fields.get("name") or fields.get("path"):
            profile = re.sub(r"\.[^.\\]*\.\d+$|\.[A-Z0-9-]+$", "", user or "")
            rows.append({
                "browser": browser or "unknown", "extension_id": _extension_id(fields), "name": fields.get("name", ""),
                "description": fields.get("description"), "version": fields.get("version"),
                "updated_at": _date(fields.get("update date")), "path": fields.get("path"), "profile_user": user,
                "principal": principal(name=profile, aliases=ctx.aliases, host_id=ctx.host_id) if profile else None,
            })
        fields = {}

    for line in text.split("\n"):
        m = USER.match(line)
        if m:
            flush()
            user = m.group(1)
            continue
        m = BROWSER.match(line.strip())
        if m:
            flush()
            browser = m.group(1)
            continue
        if not line.strip():
            flush()
            continue
        m = FIELD.match(line)
        if m:
            fields[m.group(1).strip().lower()] = m.group(2).strip()
    flush()
    return {"browser_extensions": rows}
