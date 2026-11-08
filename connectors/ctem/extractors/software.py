"""S1: installed software from 20811, 22869, 83991 and 178102."""
import re
from datetime import datetime, timezone

VERSION = 1
WINDOWS_LINE = re.compile(r"^\s*(.+?)\s{2,}\[version ([^\]]*)\](?:\s+\[installed on (\d{4}/\d{2}/\d{2})\])?\s*$")
WINDOWS_NO_VERSION = re.compile(r"^\s*(.+?)\s+\[installed on (\d{4}/\d{2}/\d{2})\]\s*$")
MAC_LINE = re.compile(r"^(\S.*?) \[version ([^\]]*)\]\s*$")
DEB_LINE = re.compile(r"^\s*([a-zA-Z]{2,3})\s+(\S+)\s+(\S+)\s+\S+")
RPM_LINE = re.compile(r"^\s*(.+?)-([^-|\s]+-[^-|\s]+)\|")
SKIP = re.compile(r"^\s*(the following|here is the list|system profiler|====output truncated|location:|hostname:|hostid:|release:|kernel)", re.I)


def _date(value):
    if not value:
        return None
    try:
        return datetime.strptime(value, "%Y/%m/%d").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def _windows(text):
    for line in text.split("\n"):
        if not line.strip() or SKIP.match(line):
            continue
        m = WINDOWS_LINE.match(line)
        if m:
            yield {"name": m.group(1), "version": m.group(2).strip(), "installed_at": _date(m.group(3)), "source_type": "windows"}
            continue
        m = WINDOWS_NO_VERSION.match(line)
        if m:
            yield {"name": m.group(1), "version": "", "installed_at": _date(m.group(2)), "source_type": "windows"}
            continue
        if "[version" not in line and "OUTPUT TRUNCATED" not in line:
            yield {"name": line.strip(), "version": "", "installed_at": None, "source_type": "windows"}


def _packages(text):
    head = text.split("\n", 1)[0]
    for line in text.split("\n")[1:]:
        if not line.strip() or SKIP.match(line):
            continue
        if "Debian" in head or "Ubuntu" in head:
            m = DEB_LINE.match(line)
            if m and m.group(1).startswith("i"):
                yield {"name": m.group(2), "version": m.group(3), "installed_at": None, "source_type": "deb"}
            continue
        m = RPM_LINE.match(line)
        if m:
            yield {"name": m.group(1), "version": m.group(2), "installed_at": None, "source_type": "rpm"}
            continue
        parts = line.split()
        if "Mac OS" in head and len(parts) >= 2:
            yield {"name": " ".join(parts[:-1]), "version": parts[-1], "installed_at": None, "source_type": "mac"}
        elif len(parts) >= 1 and "Solaris" not in head:
            package = re.match(r"(.+?)-(\d[^\s]*)$", parts[0])
            if package:
                yield {"name": package.group(1), "version": package.group(2), "installed_at": None, "source_type": "other"}


def _mac(text):
    for line in text.split("\n"):
        m = MAC_LINE.match(line)
        if m:
            yield {"name": m.group(1), "version": m.group(2), "installed_at": None, "source_type": "mac"}


def _version_report(text):
    name = None
    for line in text.split("\n"):
        m = re.match(r"^ - (.+)$", line)
        if m:
            name = m.group(1).strip()
            continue
        m = re.match(r"^\s+Best Confidence Version\s*:\s*(.*)$", line)
        if m and name:
            yield {"name": name, "version": m.group(1).strip(), "installed_at": None, "source_type": "windows"}
            name = None


def parse(plugin_id, text, ctx):
    reader = {20811: _windows, 22869: _packages, 83991: _mac, 178102: _version_report}[plugin_id]
    return {"software": list(reader(text))}
