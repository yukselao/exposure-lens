import json
from datetime import datetime, timezone

from decode import decode_attributes, timestamp
from mapping import to_row


def attr(name, value, value_type="string"):
    return {"name": name, "value": json.dumps(value), "valueType": value_type}


def obj(attributes, **extra):
    return {"id": 7626, "objectId": "1:85599cf7-f7aa", "type": "LDAP", "directoryId": 1,
            "attributes": attributes, **extra}


def test_decode_json_encoded_values():
    got = decode_attributes([attr("objectClass", ["top", "user"], "array/string"),
                             attr("primaryGroupID", 515, "integer"),
                             {"name": "odd", "value": "not json", "valueType": "string"}])
    assert got == {"objectclass": ["top", "user"], "primarygroupid": 515, "odd": "not json"}


def test_timestamp():
    assert timestamp("2022-11-10T09:43:58.3138902Z") == datetime(2022, 11, 10, 9, 43, 58, 313890, timezone.utc)
    assert timestamp("1601-01-01T00:00:00.0000000Z") is None
    assert timestamp("NEVER") is None


def test_computer_row():
    table, row = to_row(obj([
        attr("objectclass", ["top", "person", "organizationalPerson", "user", "computer"], "array/string"),
        attr("objectsid", "S-1-5-21-1-2-3-5517"), attr("samaccountname", "WS-MARKETING1$"),
        attr("cn", "WS-marketing1"), attr("useraccountcontrol", "PASSWD_NOTREQD WORKSTATION"),
        attr("operatingsystem", "Windows 10 Pro"), attr("dnshostname", "ws-marketing1.alsid.corp"),
        attr("distinguishedname", "CN=WS-marketing1,OU=Tier-2,DC=alsid,DC=corp")]))
    assert table == "ad_computers"
    assert row["object_key"] == "s-1-5-21-1-2-3-5517" and row["object_guid"] == "85599cf7-f7aa"
    assert row["enabled"] is True and row["os"] == "Windows 10 Pro"
    assert row["name"] == "WS-marketing1" and row["tie_id"] == 7626


def test_user_types_and_disabled_flag():
    base = [attr("objectclass", ["top", "person", "organizationalPerson", "user"], "array/string")]
    assert to_row(obj(base))[1]["account_type"] == "user"
    spn = base + [attr("serviceprincipalname", ["MSSQLSvc/db1"], "array/string"),
                  attr("useraccountcontrol", "DISABLE NORMAL")]
    table, row = to_row(obj(spn, derived={"isweak": True}))
    assert (table, row["account_type"], row["enabled"]) == ("ad_users", "service", False)
    assert row["attributes"]["_derived"] == {"isweak": True}


def test_gmsa_goes_to_users_and_objects_without_sid_key_on_object_id():
    gmsa = [attr("objectclass", ["top", "user", "computer", "msDS-GroupManagedServiceAccount"], "array/string")]
    table, row = to_row(obj(gmsa))
    assert (table, row["account_type"]) == ("ad_users", "gmsa")
    assert row["object_key"] == "1:85599cf7-f7aa" and row["sid"] is None


def test_group_gpo_and_unknown_class():
    table, row = to_row(obj([attr("objectclass", ["top", "group"], "array/string"),
                             attr("member", ["CN=a", "CN=b"], "array/string"),
                             attr("grouptype", "SECURITY_ENABLED ACCOUNT_GROUP")]))
    assert (table, row["member_count"], row["group_type"]) == ("ad_groups", 2, "ACCOUNT_GROUP SECURITY_ENABLED")
    table, row = to_row(obj([attr("objectclass", ["top", "container", "groupPolicyContainer"], "array/string"),
                             attr("displayname", "Default Domain Policy")]))
    assert (table, row["display_name"]) == ("ad_group_policies", "Default Domain Policy")
    assert to_row(obj([attr("objectclass", ["top", "organizationalUnit"], "array/string")])) == (None, None)
