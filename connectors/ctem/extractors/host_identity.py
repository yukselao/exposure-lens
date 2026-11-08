"""S12: host names and domain membership."""
import re

VERSION = 1


def _fields(text):
    return dict((k.strip().lower(), v.strip()) for k, v in re.findall(r"^\s*([A-Za-z][A-Za-z ]*?)\s*:[ \t]*(.*)$", text, re.M))


def parse(plugin_id, text, ctx):
    row = {}
    f = _fields(text)
    if plugin_id in (170631, 170396, 213471):
        role = f.get("domain role", "")
        row.update(sam_account_name=f.get("samaccountname"), domain=f.get("domain"), domain_role=role or None,
                   dn=f.get("distinguished name"))
        dns = f.get("dns name", "")
        if "." in dns:
            row["fqdn"] = dns
        elif dns:
            row["hostname"] = dns
        if "standalone" in role.lower():
            row["workgroup"], row["domain"] = row.get("domain"), None
    elif plugin_id == 55472:
        m = re.search(r"Hostname\s*:\s*(\S+)", text)
        row["hostname"] = m.group(1) if m else None
    elif plugin_id == 12053:
        m = re.search(r"resolves as (\S+?)\.?\s*$", text, re.M)
        row["fqdn"] = m.group(1) if m else None
    elif plugin_id == 10150:
        names = re.findall(r"^\s*(\S+)\s*=\s*(.+?)\s*$", text, re.M)
        for name, label in names:
            if label == "Computer name":
                row["netbios_name"] = name
            elif label == "Workgroup / Domain name":
                row["workgroup"] = name
    elif plugin_id == 10785:
        computer = f.get("netbios computer name")
        domain = f.get("dns domain name") or f.get("netbios domain name")
        row.update(netbios_name=computer, fqdn=f.get("dns computer name"))
        if domain and computer and domain.split(".")[0].lower() != computer.lower():
            row["domain"] = domain
        elif domain:
            row["workgroup"] = domain
    row = {k: v for k, v in row.items() if v}
    return {"host_identity": [row] if row else []}
