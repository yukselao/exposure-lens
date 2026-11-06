"""Map a TIE AD object to a row of ad_users, ad_groups, ad_computers or ad_group_policies."""
from elcommon.jsonsafe import clean

from decode import as_list, decode_attributes, flags, integer, text, timestamp

SERVICE_ACCOUNT_CLASSES = {"msds-groupmanagedserviceaccount", "msds-managedserviceaccount"}


def object_classes(attributes: dict) -> set:
    return {str(c).lower() for c in as_list(attributes.get("objectclass"))}


def table_for(attributes: dict):
    """Which table an object belongs to, or None when it is not one we store.
    Managed service accounts carry the `computer` class in AD but are accounts,
    so they go to ad_users."""
    classes = object_classes(attributes)
    if classes & SERVICE_ACCOUNT_CLASSES:
        return "ad_users"
    if "computer" in classes:
        return "ad_computers"
    if "user" in classes:
        return "ad_users"
    if "group" in classes:
        return "ad_groups"
    if "grouppolicycontainer" in classes:
        return "ad_group_policies"
    return None


def _enabled(attributes: dict):
    if "useraccountcontrol" not in attributes:
        return None
    value = attributes["useraccountcontrol"]
    if isinstance(value, int):
        return not value & 0x2
    # TIE names the flag DISABLE; ACCOUNTDISABLE is the Microsoft name.
    return not flags(value) & {"DISABLE", "ACCOUNTDISABLE"}


def _common(obj: dict, attributes: dict) -> dict:
    object_id = str(obj.get("objectId") or "")
    sid = text(attributes.get("objectsid"))
    guid = text(attributes.get("objectguid")) or (object_id.split(":", 1)[1] if ":" in object_id else None)
    stored = dict(attributes)
    if obj.get("derived"):
        stored["_derived"] = clean(obj["derived"])
    return {
        "directory_id": integer(obj.get("directoryId")) or 0,
        "tie_id": integer(obj.get("id")),
        "object_id": object_id,
        # Same key as the TIE control graph: lower-case SID, else lower-case objectId.
        "object_key": (sid or object_id).lower(),
        "object_guid": guid,
        "sid": sid,
        "dn": text(attributes.get("distinguishedname")),
        "sam_account_name": text(attributes.get("samaccountname")),
        "name": text(attributes.get("displayname")) or text(attributes.get("cn"))
        or text(attributes.get("name")) or text(attributes.get("samaccountname")),
        "when_created": timestamp(attributes.get("whencreated")),
        "when_changed": timestamp(attributes.get("whenchanged")),
        "attributes": stored,
    }


def _last_logon(attributes: dict):
    return timestamp(attributes.get("lastlogontimestamp")) or timestamp(attributes.get("lastlogon"))


def user_row(obj, attributes):
    classes = object_classes(attributes)
    if classes & SERVICE_ACCOUNT_CLASSES:
        account_type = "gmsa"
    elif as_list(attributes.get("serviceprincipalname")):
        account_type = "service"
    else:
        account_type = "user"
    return {**_common(obj, attributes),
            "account_type": account_type,
            "enabled": _enabled(attributes),
            "user_principal_name": text(attributes.get("userprincipalname")),
            "display_name": text(attributes.get("displayname")),
            "admin_count": integer(attributes.get("admincount")),
            "last_logon": _last_logon(attributes),
            "pwd_last_set": timestamp(attributes.get("pwdlastset"))}


def group_row(obj, attributes):
    group_type = attributes.get("grouptype")
    return {**_common(obj, attributes),
            "group_type": " ".join(sorted(flags(group_type))) if isinstance(group_type, (str, list))
            else text(group_type),
            "member_count": len(as_list(attributes.get("member"))),
            "description": text(attributes.get("description"))}


def computer_row(obj, attributes):
    return {**_common(obj, attributes),
            "enabled": _enabled(attributes),
            "dns_host_name": text(attributes.get("dnshostname")),
            "os": text(attributes.get("operatingsystem")),
            "os_version": text(attributes.get("operatingsystemversion")),
            "last_logon": _last_logon(attributes)}


def group_policy_row(obj, attributes):
    return {**_common(obj, attributes),
            "display_name": text(attributes.get("displayname")),
            "gpc_file_sys_path": text(attributes.get("gpcfilesyspath"))}


ROW_BUILDERS = {
    "ad_users": user_row,
    "ad_groups": group_row,
    "ad_computers": computer_row,
    "ad_group_policies": group_policy_row,
}


def to_row(obj: dict):
    """(table name, row) for a TIE object, or (None, None) when it is skipped."""
    attributes = clean(decode_attributes(obj.get("attributes")))
    table = table_for(attributes)
    if table is None or integer(obj.get("id")) is None:
        return None, None
    return table, ROW_BUILDERS[table](obj, attributes)
