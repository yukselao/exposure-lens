from datetime import datetime, timezone

from mapping import host_row, vulnerability_row

RECORD = {
    "pluginID": "20007", "severity": {"id": "4", "name": "Critical"}, "ip": "10.100.30.68",
    "uuid": "13c4a2ee", "port": "64463", "protocol": "tcp", "pluginName": "SSL v2/v3",
    "firstSeen": "1762463272", "lastSeen": "1762463279", "exploitAvailable": "Yes",
    "vprScore": "", "baseScore": "10.0", "cvssV3BaseScore": "9.8", "epssScore": "",
    "cve": "CVE-2014-3566, CVE-2016-0800", "dnsName": "win-1", "macAddress": "00:50:56:92:9f:43",
    "netbiosName": "UNKNOWN\\WIN-1", "operatingSystem": "Windows Server 2008 R2",
    "hostUUID": "010e4cb0", "acrScore": "5.0", "assetExposureScore": "701", "vulnUUID": "552ece79",
    "acceptRisk": "0", "recastRisk": "1", "family": {"id": "24", "name": "Service detection"},
    "repository": {"id": "6", "name": "Tenable.OT"}, "pluginText": "<plugin_output>x</plugin_output>",
}


def test_host_row_prefers_host_uuid():
    row = host_row(RECORD)
    assert (row["repository_id"], row["host_key"], row["repository_name"]) == (6, "010e4cb0", "Tenable.OT")
    assert row["ip"] == "10.100.30.68" and row["dns_name"] == "win-1"
    assert row["acr_score"] == 5.0 and row["aes_score"] == 701.0
    assert row["last_seen"] == datetime.fromtimestamp(1762463279, timezone.utc)
    assert "pluginText" not in row["raw"]


def test_host_row_falls_back_to_ip():
    record = dict(RECORD, hostUUID="")
    assert host_row(record)["host_key"] == "10.100.30.68"


def test_vulnerability_row():
    row = vulnerability_row(RECORD)
    assert (row["plugin_id"], row["port"], row["protocol"], row["severity"]) == (20007, 64463, "TCP", 4)
    assert row["vpr_score"] is None and row["epss_score"] is None
    assert row["cvss_v3"] == 9.8 and row["cvss_v2"] == 10.0
    assert row["cve"] == ["CVE-2014-3566", "CVE-2016-0800"]
    assert row["exploit_available"] is True and row["accept_risk"] is False and row["recast_risk"] is True
    assert (row["family_id"], row["family_name"]) == (24, "Service detection")
    assert row["raw"] is RECORD


def test_unset_dates_and_missing_fields():
    row = vulnerability_row({"pluginID": "1", "firstSeen": "-1", "lastSeen": "", "severity": "2"})
    assert row["first_seen"] is None and row["last_seen"] is None
    assert row["severity"] == 2 and row["port"] == 0 and row["cve"] == []
