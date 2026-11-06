"""Map a Security Center `vulndetails` record to host_assets and vulnerabilities rows."""
from datetime import datetime, timezone

HOST_RAW_FIELDS = ("ip", "ips", "uuid", "hostUUID", "hostUniqueness", "dnsName", "netbiosName",
                   "macAddress", "operatingSystem", "acrScore", "assetExposureScore",
                   "keyDrivers", "repository")


def _text(value):
    value = "" if value is None else str(value).strip()
    return value or None


def _float(value):
    try:
        return float(value) if value not in (None, "") else None
    except (TypeError, ValueError):
        return None


def _int(value, default=None):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _epoch(value):
    """SC sends epoch seconds as text; "-1" and "" mean "not set"."""
    seconds = _int(value)
    if seconds is None or seconds <= 0:
        return None
    return datetime.fromtimestamp(seconds, timezone.utc)


def _flag(value) -> bool:
    return str(value or "").strip().lower() in ("1", "true", "yes")


def _ref(value):
    """{"id": "6", "name": "x"} -> (6, "x"); anything else -> (None, text)."""
    if isinstance(value, dict):
        return _int(value.get("id")), _text(value.get("name"))
    return None, _text(value)


def host_row(record: dict) -> dict:
    repository_id, repository_name = _ref(record.get("repository"))
    ip = _text(record.get("ip"))
    host_uuid = _text(record.get("hostUUID"))
    return {
        "repository_id": repository_id or 0,
        "repository_name": repository_name or "",
        "host_key": host_uuid or ip or _text(record.get("dnsName")) or "unknown",
        "host_uuid": host_uuid,
        "agent_uuid": _text(record.get("uuid")),
        "ip": ip,
        "dns_name": _text(record.get("dnsName")),
        "netbios_name": _text(record.get("netbiosName")),
        "mac_address": _text(record.get("macAddress")),
        "os": _text(record.get("operatingSystem")),
        "acr_score": _float(record.get("acrScore")),
        "aes_score": _float(record.get("assetExposureScore")),
        "first_seen": _epoch(record.get("firstSeen")),
        "last_seen": _epoch(record.get("lastSeen")),
        "raw": {k: record[k] for k in HOST_RAW_FIELDS if k in record},
    }


def vulnerability_row(record: dict) -> dict:
    """Everything except host_asset_id, which the caller fills in."""
    severity = record.get("severity")
    family_id, family_name = _ref(record.get("family"))
    return {
        "plugin_id": _int(record.get("pluginID"), 0),
        "port": _int(record.get("port"), 0),
        "protocol": (_text(record.get("protocol")) or "").upper(),
        "vuln_uuid": _text(record.get("vulnUUID")),
        "plugin_name": _text(record.get("pluginName")) or _text(record.get("name")) or "",
        "family_id": family_id,
        "family_name": family_name,
        "severity": _int(severity.get("id") if isinstance(severity, dict) else severity, 0),
        "vpr_score": _float(record.get("vprScore")),
        "cvss_v3": _float(record.get("cvssV3BaseScore")),
        "cvss_v2": _float(record.get("baseScore")),
        "epss_score": _float(record.get("epssScore")),
        "cve": [c.strip() for c in str(record.get("cve") or "").split(",") if c.strip()],
        "exploit_available": _flag(record.get("exploitAvailable")),
        "accept_risk": _flag(record.get("acceptRisk")),
        "recast_risk": _flag(record.get("recastRisk")),
        "first_seen": _epoch(record.get("firstSeen")),
        "last_seen": _epoch(record.get("lastSeen")),
        "raw": record,
    }
