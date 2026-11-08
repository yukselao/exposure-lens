#!/usr/bin/env python3
"""Rebuild db/data/plugin_catalog.json from a Security Center plugin database.

Reads the local, informational plugins from plugins.db over SSH (read-only) and
adds the handbook category and the scenario of each plugin. Run it when the
plugin feed has moved on; the Alembic data step loads the resulting file.

    python3 tools/import_plugin_catalog.py [ssh-host]      (default: sc4)
"""
import json
import pathlib
import re
import subprocess
import sys
import urllib.request

HANDBOOK = "https://yukselao.github.io/nessus-threat-hunting-handbook/"
OUT = pathlib.Path(__file__).resolve().parent.parent / "db" / "data" / "plugin_catalog.json"
SEP = "\x1f"

SQL = ("select p.id, coalesce(f.name,''), coalesce(p.agent,''), coalesce(p.sourceFile,''), "
       "replace(replace(coalesce(p.dependencies,''),char(10),' '),char(13),' '), coalesce(p.pluginModDate,''), "
       "replace(p.name,char(10),' ') from Plugin p left join Family f on f.id = p.familyID "
       "where p.type = 'active' and p.checkType = 'local' and p.riskFactor in ('None','Info','Informational') "
       "order by p.id;")

# scenario -> (extractor or None, plugin ids). A plugin with an extractor starts
# as `selected`; one without an extractor yet stays a `candidate`.
SCENARIOS = {
    "S1": ("software", [20811, 22869, 83991, 178102]),
    "S2": ("local_groups", [71246, 10902, 10904, 10901, 10903, 10905, 10906, 60019]),
    "S3": ("local_accounts", [10860, 72684, 95928, 10913, 10914, 10915, 10916, 83303]),
    "S4": ("sessions", [161502, 38689, 92373, 11459]),
    "S5": ("services", [44401]),
    "S6": (None, [70621, 70625, 70618]),
    "S7": (None, [92433, 92423, 92422, 92416]),
    "S8": (None, [10395, 60119, 10437]),
    "S9": (None, [34252, 64582, 58651, 25221, 99265]),
    "S10": (None, [16193, 159817, 237981, 367321, 159929, 38687, 92367, 17651]),
    "S11": ("scan_coverage", [19506, 141118, 110385, 104410, 110723]),
    "S12": ("host_identity", [170631, 170396, 213471, 55472, 12053, 10150, 10785]),
    "S13": (None, [10908, 10399, 69236, 69238, 69239, 167250, 167251, 167252, 168912]),
    "S14": (None, [92415, 77668, 92435, 70329, 104856, 70943]),
    "S15": ("browser_extensions", [96533, 96534, 176212, 133180, 319657, 319658, 318757, 362489,
                                   362501, 362512, 370288, 362499, 316968]),
    "S16": (None, [35730, 63080]),
}
# Plugins that say their detail is in an attached file.
HAS_ATTACHMENTS = {92373, 92435, 171956}
# Scenario plugins the extractor cannot read yet: no sample output to write against.
NO_SAMPLE = {95929, 171957, 365664, 133180}
PRODUCT_CHECK = re.compile(r"\binstalled\b|detection|\bdetect\b", re.I)


def handbook():
    request = urllib.request.Request(HANDBOOK, headers={"User-Agent": "Mozilla/5.0"})
    html = urllib.request.urlopen(request, timeout=60).read().decode("utf-8", "replace")
    script = max(re.findall(r"<script[^>]*>(.*?)</script>", html, flags=re.S), key=len)
    categories = {}
    for entry in re.findall(r"P\(\{(.*?)\}\);", script, flags=re.S):
        pid = re.search(r"\bid:(\d+)", entry)
        category = re.search(r"\bc:\"(\w+)\"", entry)
        if pid and category:
            categories[int(pid.group(1))] = category.group(1)
    return categories


def main():
    host = sys.argv[1] if len(sys.argv) > 1 else "sc4"
    command = f"sudo -n sqlite3 -readonly -separator $'{SEP}' /opt/sc/plugins.db \"{SQL}\""
    result = subprocess.run(["ssh", "-o", "BatchMode=yes", host, command], capture_output=True, text=True, check=True)
    by_scenario = {pid: (code, extractor) for code, (extractor, ids) in SCENARIOS.items() for pid in ids}
    categories = handbook()
    rows = []
    for line in result.stdout.splitlines():
        parts = line.split(SEP, 6)
        if len(parts) != 7 or not parts[0].isdigit():
            continue
        pid = int(parts[0])
        code, extractor = by_scenario.get(pid, (None, None))
        if pid in NO_SAMPLE:
            extractor = None
        rows.append({
            "plugin_id": pid, "name": parts[6], "family": parts[1], "agent": parts[2] == "true",
            "source_file": parts[3], "dependencies": parts[4],
            "plugin_class": "product" if PRODUCT_CHECK.search(parts[6]) and "numerat" not in parts[6] else "inventory",
            "handbook_category": categories.get(pid), "scenario": code, "extractor": extractor,
            "has_attachments": pid in HAS_ATTACHMENTS,
            "review_status": "selected" if extractor else "candidate",
        })
    # Scenario plugins that run remotely are not in the local filter but belong in the catalog.
    known = {r["plugin_id"] for r in rows}
    missing = sorted(set(by_scenario) - known)
    if missing:
        ids = ",".join(map(str, missing))
        sql = SQL.split(" where ")[0] + f" where p.id in ({ids}) order by p.id;"
        command = f"sudo -n sqlite3 -readonly -separator $'{SEP}' /opt/sc/plugins.db \"{sql}\""
        extra = subprocess.run(["ssh", "-o", "BatchMode=yes", host, command], capture_output=True, text=True, check=True)
        for line in extra.stdout.splitlines():
            parts = line.split(SEP, 6)
            if len(parts) != 7 or not parts[0].isdigit():
                continue
            pid = int(parts[0])
            code, extractor = by_scenario[pid]
            if pid in NO_SAMPLE:
                extractor = None
            rows.append({"plugin_id": pid, "name": parts[6], "family": parts[1], "agent": parts[2] == "true",
                         "source_file": parts[3], "dependencies": parts[4], "plugin_class": "inventory",
                         "handbook_category": categories.get(pid), "scenario": code, "extractor": extractor,
                         "has_attachments": pid in HAS_ATTACHMENTS,
                         "review_status": "selected" if extractor else "candidate"})
    rows.sort(key=lambda r: r["plugin_id"])
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(rows, ensure_ascii=False, indent=0) + "\n", encoding="utf-8")
    print(f"{len(rows)} plugins written to {OUT}")


if __name__ == "__main__":
    main()
