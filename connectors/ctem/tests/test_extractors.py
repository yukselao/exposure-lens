import pathlib

import pytest

from extractors import Context, MODULES
from identity import principal

FIXTURES = pathlib.Path(__file__).parent / "fixtures"


def fixture(name):
    return (FIXTURES / f"{name}.txt").read_text()


def run(module, plugin_id, text, aliases=("HOST1",)):
    return MODULES[module].parse(plugin_id, text, Context(1, aliases))


@pytest.mark.parametrize("raw,scope,domain,name", [
    ("LABNET\\nessus", "domain", "LABNET", "nessus"),
    ("labnet.local\\nessus", "domain", "labnet.local", "nessus"),
    ("qa-user@labs.demo.io", "domain", "labs.demo.io", "qa-user"),
    (".\\Administrator", "local", None, "Administrator"),
    ("HOST1\\Administrator", "local", "HOST1", "Administrator"),
    ("NT AUTHORITY\\LocalService", "builtin", "NT AUTHORITY", "LocalService"),
    ("LocalSystem", "builtin", None, "LocalSystem"),
    ("Administrator", "unknown", None, "Administrator"),
    ("10.100.30.92\\Administrator", "local", "10.100.30.92", "Administrator"),
])
def test_principal_scope(raw, scope, domain, name):
    who = principal(raw, aliases=("HOST1",), host_id=1)
    assert (who["scope"], who["domain"], who["name"]) == (scope, domain, name)


def test_spellings_of_one_domain_account_share_a_key():
    keys = {principal(r, host_id=1)["natural_key"] for r in ("LABNET\\Nessus", "labnet.local\\nessus", "nessus@labnet.local")}
    assert keys == {"d:labnet\\nessus"}


def test_privileged_hint():
    assert principal(domain="LABNET", name="Domain Admins", kind="group")["privileged_hint"] is True
    assert principal(domain="LABNET", name="x", sid="S-1-5-21-1-2-3-500")["privileged_hint"] is True
    assert principal(domain="LABNET", name="x", sid="S-1-5-21-1-2-3-1105")["privileged_hint"] is False


def test_empty_identity_is_dropped():
    assert principal(domain="", name="") is None


def test_software_windows():
    text = ("The following software are installed on the remote host :\n\n"
            "Google Chrome  [version 109.0.5414.120]  [installed on 2023/01/27]\n"
            "Microsoft SQL Server 2008 R2\nNpcap  [version 1.60]\n"
            "SIMATIC HMI Touch Input  [version 13.00.0100]  [ins\n====OUTPUT TRUNCATED (7933)====")
    rows = run("software", 20811, text)["software"]
    assert rows[0]["name"] == "Google Chrome" and rows[0]["version"] == "109.0.5414.120"
    assert rows[0]["installed_at"].year == 2023
    assert rows[1] == {"name": "Microsoft SQL Server 2008 R2", "version": "", "installed_at": None, "source_type": "windows"}
    assert rows[2]["version"] == "1.60" and len(rows) == 3


@pytest.mark.parametrize("name", ["20811_1", "20811_2"])
def test_software_windows_fixtures(name):
    assert len(run("software", 20811, fixture(name))["software"]) > 3


def test_software_debian_and_rpm():
    deb = "Here is the list of packages installed on the remote Debian Linux system : \n\n  ii   apache2  2.4.56-1~deb11u1  amd64  Apache HTTP Server\n"
    rpm = "Here is the list of packages installed on the remote CentOS Linux system : \n\n  cups-filters-1.0.35-29.el7_9|(none)      Fri 30 Sep 2022 12:06:42 PM EDT\n"
    assert run("software", 22869, deb)["software"][0]["version"] == "2.4.56-1~deb11u1"
    row = run("software", 22869, rpm)["software"][0]
    assert (row["name"], row["version"], row["source_type"]) == ("cups-filters", "1.0.35-29.el7_9", "rpm")


def test_software_mac():
    rows = run("software", 83991, fixture("83991_1"))["software"]
    assert rows[0]["name"] == "50onPaletteServer" and rows[0]["version"] == "1.1.0"


def test_software_version_report():
    assert run("software", 178102, fixture("178102_1"))["software"][0] == {
        "name": "Java Auto Updater", "version": "9.0.1.0", "installed_at": None, "source_type": "windows"}


def test_local_groups_wmi_with_domain_group_and_no_sid():
    text = ("Group Name : Administrators\nHost Name  : EX-EMPIRE-05\nGroup SID  : S-1-5-32-544\nMembers    : \n"
            "  Name : Administrator\n    Domain : EX-EMPIRE-05\n    Class  : Win32_UserAccount\n    SID    : S-1-5-21-1-2-3-500\n"
            "  Name : Domain Admins\n    Domain : LABNET\n    Class  : Win32_Group\n    SID    : \n\n"
            "Group Name : Backup Operators\nHost Name  : EX-EMPIRE-05\nGroup SID  : S-1-5-32-551\nMembers    : \n")
    rows = run("local_groups", 71246, text)["group_members"]
    assert len(rows) == 2
    assert rows[0]["principal"]["scope"] == "local"
    second = rows[1]["principal"]
    assert (second["kind"], second["scope"], second["domain"], second["sid"]) == ("group", "domain", "LABNET", None)
    assert rows[1]["group_sid"] == "S-1-5-32-544"


def test_local_groups_list():
    text = "The following user is a member of the 'Administrators' group :\n\n  - WIN2019SRVLAB\\Administrator (User)\n  - LABNET\\Domain Admins (Group)\n"
    rows = run("local_groups", 10902, text, aliases=("WIN2019SRVLAB",))["group_members"]
    assert rows[0]["principal"]["scope"] == "local" and rows[1]["principal"]["kind"] == "group"
    assert rows[0]["group_sid"] == "S-1-5-32-544"


def test_local_accounts():
    rows = run("local_accounts", 10860, fixture("10860_1"))["local_accounts"]
    assert rows[0]["principal"]["name"] == "Administrator" and rows[0]["rid"] == 500
    rows = run("local_accounts", 72684, fixture("72684_1"))["local_accounts"]
    assert rows[1]["principal"]["name"] == "cymptom" and rows[1]["disabled"] is True and rows[1]["rid"] == 1002
    assert [r["principal"]["name"] for r in run("local_accounts", 10914, fixture("10914_1"))["local_accounts"]] == ["Guest"]


def test_linux_accounts():
    text = ("-----------[ User Accounts ]-----------\n\nUser         : administrator\nHome folder  : /home/administrator\n"
            "Start script : /bin/bash\nGroups       : video\n               sudo\n\n"
            "----------[ System Accounts ]----------\n\nUser         : root\nHome folder  : /root\n")
    rows = run("local_accounts", 95928, text)["local_accounts"]
    assert rows[0]["groups"] == ["video", "sudo"] and rows[0]["shell"] == "/bin/bash" and rows[0]["account_type"] == "user"
    assert rows[1]["account_type"] == "system"


def test_sessions():
    text = "Logged on users :\n  - S-1-5-21-5-7-3-500\n    Domain   : SCHNITZEL\n    Username : Administrator\n  - S-1-5-21-1-2-3-1105\n    Domain   : LABNET\n    Username : nessus\n"
    rows = run("sessions", 161502, text, aliases=("SCHNITZEL",))["sessions"]
    assert [r["principal"]["scope"] for r in rows] == ["local", "domain"]
    assert rows[1]["principal"]["sid"].endswith("-1105")
    assert run("sessions", 38689, "Last Successful logon : .\\Administrator")["sessions"][0]["principal"]["scope"] == "local"
    rows = run("sessions", 92373, fixture("92373_2"))["sessions"]
    assert rows and all(r["session_type"] == "smb_session" for r in rows)


def test_services():
    text = ("The following services are set to start automatically :\n\n  BFE startup parameters :\n    Display name : Base Filtering Engine\n"
            "    Service name : BFE\n    Log on as : NT AUTHORITY\\LocalService\n    Executable path : C:\\Windows\\system32\\svchost.exe -k x\n"
            "    Dependencies : RpcSs/\n\n  Sync startup parameters :\n    Display name : AD Sync\n    Service name : ADSync\n"
            "    Log on as : DEMO\\ADSyncMSAf438b$\n    Executable path : C:\\sync.exe\n")
    rows = run("services", 44401, text)["services"]
    assert rows[0]["principal"]["scope"] == "builtin" and rows[0]["start_type"] == "automatically"
    second = rows[1]
    assert second["service_name"] == "ADSync" and second["principal"]["kind"] == "computer" and second["principal"]["domain"] == "DEMO"


def test_services_fixture():
    assert len(run("services", 44401, fixture("44401_1"))["services"]) > 10


def test_scan_coverage():
    rows = run("scan_coverage", 141118, fixture("141118_2"), aliases=("10.100.30.35", "10.100.30.92"))["scan_coverage"]
    assert rows[0]["protocol"] == "SMB" and rows[0]["status"] == "credentialed" and rows[0]["principal"]["scope"] == "local"
    row = run("scan_coverage", 19506, fixture("19506_1"))["scan_coverage"][0]
    assert row["scan_name"] == "DataCenters Scan - Dec 2023"


def test_host_identity():
    row = run("host_identity", 170631, fixture("170631_1"))["host_identity"][0]
    assert row["domain"] == "demo.io" and row["sam_account_name"] == "BIGFIX-CLUS-APP$" and row["domain_role"] == "MemberServer"
    row = run("host_identity", 10785, fixture("10785_1"))["host_identity"][0]
    assert row["domain"] == "TADH.local" and row["fqdn"] == "DC01-H.TADH.local"
    row = run("host_identity", 10150, fixture("10150_1"))["host_identity"][0]
    assert row["netbios_name"] == "AL-WIN10-RG1" and row["workgroup"] == "LABNET"
    assert run("host_identity", 12053, fixture("12053_1"))["host_identity"][0]["fqdn"] == "demo-win2012.labnet.local"


def test_browser_chrome():
    rows = run("browser_extensions", 96533, fixture("96533_1"))["browser_extensions"]
    first = rows[0]
    assert first["browser"] == "Chrome" and first["name"] == "Google Docs Offline" and first["version"] == "1.42.0"
    assert first["extension_id"] == "ghbmnnjooekpmoecnnnilnnbdlolhkhi" and first["updated_at"].year == 2024
    assert first["principal"]["name"] == "Administrator"


def test_browser_firefox_and_edge():
    row = run("browser_extensions", 96534, fixture("96534_1"))["browser_extensions"][0]
    assert row["browser"] == "Firefox" and row["extension_id"] == "doh-rollout@mozilla.org"
    rows = run("browser_extensions", 176212, fixture("176212_1"))["browser_extensions"]
    assert rows[0]["browser"] == "Microsoft Edge" and rows[0]["name"] == "unknown"
