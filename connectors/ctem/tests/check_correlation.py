"""Correlation check against the live database. Everything runs in one
transaction that is rolled back, so nothing it creates stays.

    docker compose run --rm --no-deps ctem python /app/tests/check_correlation.py
"""
import logging
import sys

from sqlalchemy import text

from eldb.database import new_session

sys.path.insert(0, "/app")
import correlate  # noqa: E402


class Log:
    def info(self, *a, **k):
        pass


s = new_session()
q = lambda sql, **p: s.execute(text(sql), p)
one = lambda sql, **p: s.execute(text(sql), p).scalar()

domain = q("SELECT netbios_name, dns_name, directory_id, nc_name FROM ad_domains WHERE lower(netbios_name) = 'tcorp'").first()
assert domain, "ad_domains has no TCORP row; run a TIE sync first"
user = q("""SELECT u.object_key, u.sam_account_name, u.sid FROM ad_users u JOIN ad_tier_assignments t USING (object_key)
            WHERE t.tier = 0 AND u.directory_id = :d AND lower(u.dn) LIKE '%' || lower(:nc) AND u.account_type = 'user' LIMIT 1""",
         d=domain.directory_id, nc=domain.nc_name).first()
plain = q("""SELECT u.object_key, u.sam_account_name FROM ad_users u WHERE u.directory_id = :d AND lower(u.dn) LIKE '%' || lower(:nc)
             AND u.account_type = 'user' AND u.object_key NOT IN (SELECT object_key FROM ad_tier_assignments) LIMIT 1""",
          d=domain.directory_id, nc=domain.nc_name).first()
group = q("""SELECT g.object_key, g.sam_account_name, count(m.member_key) n FROM ad_groups g JOIN ad_group_members m ON m.group_key = g.object_key
             JOIN ad_users u ON u.object_key = m.member_key
             WHERE g.directory_id = :d AND lower(g.dn) LIKE '%' || lower(:nc) GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 1""",
          d=domain.directory_id, nc=domain.nc_name).first()
host = one("SELECT host_asset_id FROM host_risk WHERE critical > 0 ORDER BY critical DESC LIMIT 1")
output = one("SELECT id FROM plugin_outputs WHERE host_asset_id = :h LIMIT 1", h=host)


def principal(key, kind, scope, dom, name, sid=None, host_id=None):
    return one("""INSERT INTO principals (natural_key, kind, scope, domain, name, sid, host_asset_id)
                  VALUES (:k, :kind, :scope, :dom, :name, :sid, :h) RETURNING id""",
               k=key, kind=kind, scope=scope, dom=dom, name=name, sid=sid, h=host_id)


by_sid = principal("t:sid", "user", "domain", "X", "x", user.sid)
by_name = principal("t:name", "user", "domain", domain.netbios_name, user.sam_account_name)
by_dns = principal("t:dns", "user", "domain", domain.dns_name, plain.sam_account_name)
adm_group = principal("t:group", "group", "domain", domain.netbios_name, group.sam_account_name)
local = principal("t:local", "user", "local", None, plain.sam_account_name, None, host)
bare_nodomain = principal("t:bare1", "user", "unknown", None, plain.sam_account_name, None, host)
q("""INSERT INTO host_sessions (host_asset_id, source_output_id, plugin_id, principal_id, session_type)
     VALUES (:h, :o, 161502, :p, 'session')""", h=host, o=output, p=by_sid)
q("""INSERT INTO host_sessions (host_asset_id, source_output_id, plugin_id, principal_id, session_type)
     VALUES (:h, :o, 92373, :p, 'smb_session')""", h=host, o=output, p=by_name)
q("""INSERT INTO host_group_members (host_asset_id, source_output_id, plugin_id, group_name, group_sid, principal_id)
     VALUES (:h, :o, 71246, 'Administrators', 'S-1-5-32-544', :p)""", h=host, o=output, p=adm_group)
q("""INSERT INTO host_services (host_asset_id, source_output_id, plugin_id, service_name, principal_id)
     VALUES (:h, :o, 44401, 'TestSvc', :p)""", h=host, o=output, p=by_dns)
q("""INSERT INTO host_sessions (host_asset_id, source_output_id, plugin_id, principal_id, session_type)
     VALUES (:h, :o, 38689, :p, 'last_logon')""", h=host, o=output, p=bare_nodomain)
q("""INSERT INTO host_sessions (host_asset_id, source_output_id, plugin_id, principal_id, session_type)
     VALUES (:h, :o, 38689, :p, 'last_logon')""", h=host, o=output, p=local)

stats = correlate.correlate(s, Log())
get = lambda pid: q("SELECT ad_object_key, resolved_by FROM principals WHERE id = :p", p=pid).first()
checks = [
    ("SID match", get(by_sid) == (user.object_key, "sid")),
    ("DOMAIN\\name match", get(by_name) == (user.object_key, "domain_name")),
    ("DNS domain match", get(by_dns) == (plain.object_key, "domain_name")),
    ("group match", get(adm_group)[0] == group.object_key),
    ("local account stays local", get(local)[0] is None),
    ("bare name on a host outside the domain stays unmatched", get(bare_nodomain)[0] is None),
    ("Tier 0 session gives a HasSession edge", one("SELECT count(*) FROM attack_edges WHERE edge_type = 'HasSession' AND target_key = :k", k=user.object_key) >= 1),
    ("group admin gives an AdminTo edge", one("SELECT count(*) FROM attack_edges WHERE edge_type = 'AdminTo' AND source_key = :k", k=group.object_key) == 1),
    ("group members inherit the right", one("SELECT count(*) FROM identity_host_access WHERE via_group_key = :k AND host_asset_id = :h", k=group.object_key, h=host) >= int(group.n)),
    ("service account gives RunsServiceAs", one("SELECT count(*) FROM attack_edges WHERE edge_type = 'RunsServiceAs' AND target_key = :k", k=plain.object_key) == 1),
    ("Tier 0 question sees the host", one("SELECT count(*) FROM v_risk_access WHERE tier = 0 AND tier0_like AND access_class = 'usage' AND identity_key = :k AND host_asset_id = :h", k=user.object_key, h=host) >= 1),
    ("critical question sees the user", one("SELECT count(*) FROM v_risk_access WHERE critical > 0 AND identity_key = :k", k=user.object_key) >= 1),
]
s.rollback()
failed = [name for name, ok in checks if not ok]
for name, ok in checks:
    print("ok  " if ok else "FAIL", name)
print("stats:", stats)
sys.exit(1 if failed else 0)
