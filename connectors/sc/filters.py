"""Turn a query's filter parameters into Tenable Security Center analysis filters.

Adapted from jira-test (ui/fastapi/app/services/tenable_sc.py). The value
formats below were confirmed against a real Security Center:

  severity                 "3,4"
  ip                       "10.0.0.1,10.0.1.0/24,10.0.2.1-10.0.2.9"
  pluginName               case-insensitive substring
  pluginID                 "20007,19506"
  cvssV3BaseScore, vprScore, assetCriticalityRating   "lo-hi"
  exploitAvailable         "true"
  family, repository       [{"id": "11"}, ...]   (a flat "11" is rejected)
"""

SEVERITY_IDS = {"info": "0", "low": "1", "medium": "2", "high": "3", "critical": "4"}


def _f(name, value):
    return {"id": name, "filterName": name, "operator": "=", "type": "vuln",
            "isPredefined": True, "value": value}


def _num_or_none(value):
    if value is None or value == "":
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def score_range(lo, hi=None, top=10.0):
    """A score range as SC's "lo-hi" value, or None for "no filter".

    The full range (0..top) must not be sent: SC then drops every finding that
    has no score at all, so "0-10" is not the same as leaving the filter out."""
    lo, hi = _num_or_none(lo), _num_or_none(hi)
    if lo is not None and lo <= 0:
        lo = None
    if hi is not None and hi >= top:
        hi = None
    if lo is None and hi is None:
        return None
    lo = 0.0 if lo is None else max(0.0, lo)
    hi = top if hi is None else min(top, hi)
    if hi < lo:
        lo, hi = hi, lo
    return f"{lo:g}-{hi:g}"


def _csv(value) -> str:
    """Normalise a comma or semicolon separated list; a list is accepted too."""
    if isinstance(value, (list, tuple)):
        parts = [str(p) for p in value]
    else:
        parts = str(value or "").replace(";", ",").split(",")
    return ",".join(p.strip() for p in parts if p.strip())


def _id_records(items) -> list:
    """[{"id": "6", "name": ...}, "7"] -> [{"id": "6"}, {"id": "7"}]."""
    ids = []
    for item in items or []:
        raw = str((item.get("id") if isinstance(item, dict) else item) or "").strip()
        if raw.isdigit() and raw not in ids:
            ids.append(raw)
    return [{"id": i} for i in ids]


def _severity_ids(value) -> list:
    parts = value if isinstance(value, (list, tuple)) else str(value or "").replace("&", ",").split(",")
    ids = set()
    for part in parts:
        key = str(part).strip().lower()
        if key in SEVERITY_IDS:
            ids.add(SEVERITY_IDS[key])
        elif key in SEVERITY_IDS.values():
            ids.add(key)
    return sorted(ids)


def build_filters(params: dict) -> list:
    """Filter parameters of an sc_queries row -> SC `filters` list. Empty or
    missing parameters add no filter."""
    params = params or {}
    filters = []

    severity = _severity_ids(params.get("severity"))
    if severity and len(severity) < len(SEVERITY_IDS):
        filters.append(_f("severity", ",".join(severity)))

    ip_range = _csv(params.get("ip_range"))
    if ip_range:
        filters.append(_f("ip", ip_range))

    name = str(params.get("name_contains") or "").strip()
    if name:
        filters.append(_f("pluginName", name))

    plugin_ids = ",".join(p for p in _csv(params.get("plugin_ids")).split(",") if p.isdigit())
    if plugin_ids:
        filters.append(_f("pluginID", plugin_ids))

    for filter_name, lo_key, hi_key in (
        ("cvssV3BaseScore", "cvss_v3_min", "cvss_v3_max"),
        ("vprScore", "vpr_min", "vpr_max"),
        ("assetCriticalityRating", "acr_min", "acr_max"),
    ):
        value = score_range(params.get(lo_key), params.get(hi_key))
        if value:
            filters.append(_f(filter_name, value))

    families = _id_records(params.get("families"))
    if families:
        filters.append(_f("family", families))

    repositories = _id_records(params.get("repositories"))
    if repositories:
        filters.append(_f("repository", repositories))

    if params.get("exploit_only") in (True, 1, "1", "true", "on"):
        filters.append(_f("exploitAvailable", "true"))
    return filters
