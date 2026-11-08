"""Turn the account text found in plugin output into one normalised identity."""
import re

BUILTIN_DOMAINS = {"nt authority", "nt service", "builtin", "nt virtual machine", "window manager",
                   "font driver host", "iis apppool", "nt-autorität", "autorite nt"}
BUILTIN_NAMES = {"localsystem", "local system", "system", "localservice", "local service",
                 "networkservice", "network service", "everyone", "authenticated users", "interactive"}
BUILTIN_SIDS = re.compile(r"^S-1-5-(18|19|20|32-\d+|80-.*|90-.*|96-.*|4|6|9|11)$|^S-1-1-0$", re.I)
PRIVILEGED_SID = re.compile(r"-(500|512|518|519)$|^S-1-5-32-544$", re.I)
PRIVILEGED_GROUPS = {"domain admins", "enterprise admins", "schema admins"}


def short_domain(domain: str) -> str:
    return domain.split(".")[0].lower() if not re.fullmatch(r"[\d.]+", domain) else domain


def principal(raw=None, *, domain=None, name=None, sid=None, kind=None, aliases=(), host_id=None):
    """Identity dict with `natural_key`, or None when there is no usable name.

    `raw` is text such as CORP\\user, user@corp.local, .\\user or user. `aliases`
    holds the names of the host, which mark an account as local."""
    if raw is not None:
        raw = raw.strip().strip("'\"")
        if "\\" in raw:
            domain, name = raw.rsplit("\\", 1)
        elif "@" in raw:
            name, domain = raw.split("@", 1)
        else:
            name = raw
    name = (name or "").strip()
    domain = (domain or "").strip() or None
    sid = (sid or "").strip() or None
    if not name or name in ("(none)", "-"):
        return None

    lname = name.lower()
    ldomain = (domain or "").lower()
    local_names = {a.lower() for a in aliases if a}
    if ldomain in BUILTIN_DOMAINS or (not domain and lname in BUILTIN_NAMES) or (sid and BUILTIN_SIDS.match(sid)):
        scope = "builtin"
    elif domain == "." or ldomain in local_names or short_domain(ldomain) in local_names or re.fullmatch(r"[\d.]+", ldomain):
        scope = "local"
    elif domain:
        scope = "domain"
    else:
        scope = "unknown"

    if kind is None:
        kind = "computer" if name.endswith("$") else "user"
    if scope == "builtin":
        key = f"b:{ldomain}\\{lname}"
    elif scope == "domain":
        key = f"d:{short_domain(ldomain)}\\{lname}"
    else:
        key = f"{'l' if scope == 'local' else 'u'}:{host_id}:{lname}"
    hint = scope == "domain" and bool((sid and PRIVILEGED_SID.search(sid)) or lname in PRIVILEGED_GROUPS)
    return {"natural_key": key, "kind": kind, "scope": scope, "domain": None if domain == "." else domain,
            "name": name, "sid": sid, "host_asset_id": host_id if scope in ("local", "unknown") else None,
            "privileged_hint": hint}
