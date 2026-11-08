"""One module per scenario. `parse(plugin_id, text, ctx)` returns a dict that
maps a record type to a list of rows; extract.py writes them. Bump VERSION in a
module when its output changes, and its plugin outputs are parsed again."""
from . import browser_extensions, host_identity, local_accounts, local_groups, scan_coverage, services, sessions, software

MODULES = {
    "software": software, "local_groups": local_groups, "local_accounts": local_accounts, "sessions": sessions,
    "services": services, "scan_coverage": scan_coverage, "host_identity": host_identity,
    "browser_extensions": browser_extensions,
}


class Context:
    """What an extractor knows about the host."""

    def __init__(self, host_id=None, aliases=()):
        self.host_id = host_id
        self.aliases = set(a for a in aliases if a)
