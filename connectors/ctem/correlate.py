"""ctem_correlate: match identities to AD, then build the access facts and edges.
Every step is set-based SQL in one transaction, so screens never see a half-built state."""
from sqlalchemy import text

from eldb.database import new_session

# Identities no table refers to any more, for example after a re-parse.
ORPHANS = """
    DELETE FROM principals p
    WHERE NOT EXISTS (SELECT 1 FROM host_group_members x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_local_accounts x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_sessions x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_services x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_scan_coverage x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_browser_extensions x WHERE x.principal_id = p.id)
      AND NOT EXISTS (SELECT 1 FROM host_iocs x WHERE x.principal_id = p.id)
"""

RESET = "UPDATE principals SET ad_object_key = NULL, resolved_by = NULL WHERE ad_object_key IS NOT NULL OR resolved_by IS NOT NULL"

# Optional mappings in settings section "identity_mapping" (no UI; set with SQL):
#   domain_aliases   {"demo": "alsid"}               a telemetry domain (NetBIOS or first DNS label) -> a TIE NetBIOS name
#   identity_aliases {"demo\\sccmadmin": "alsid\\taher"}  one telemetry identity -> one AD account
# For renamed domains, NetBIOS/DNS mismatches, or tying a demo lab to a demo directory.
DOMAIN_ALIASES = """
    SELECT lower(key) AS src, lower(value) AS dst
    FROM settings, jsonb_each_text(COALESCE(data->'domain_aliases', '{}'::jsonb)) WHERE section = 'identity_mapping'
"""

BY_ALIAS = r"""
    UPDATE principals p SET ad_object_key = m.object_key, resolved_by = 'alias'
    FROM (
        SELECT DISTINCT ON (x.id) x.id, o.object_key
        FROM principals x
        JOIN (SELECT lower(key) AS src, lower(value) AS dst
              FROM settings, jsonb_each_text(COALESCE(data->'identity_aliases', '{}'::jsonb))
              WHERE section = 'identity_mapping') a
          ON a.src = split_part(lower(x.domain), '.', 1) || '\' || lower(x.name)
        JOIN ad_domains d ON lower(d.netbios_name) = split_part(a.dst, '\', 1)
        JOIN v_ad_objects o ON o.directory_id = d.directory_id AND lower(o.sam_account_name) = split_part(a.dst, '\', 2)
                           AND (d.nc_name IS NULL OR lower(o.dn) LIKE '%' || lower(d.nc_name))
        WHERE x.scope = 'domain'
        ORDER BY x.id, o.object_key
    ) m
    WHERE p.id = m.id
"""

BY_SID = """
    UPDATE principals p SET ad_object_key = o.object_key, resolved_by = 'sid'
    FROM v_ad_objects o
    WHERE p.sid IS NOT NULL AND lower(p.sid) = o.object_key AND p.scope <> 'builtin' AND p.ad_object_key IS NULL
"""

BY_DOMAIN_NAME = r"""
    UPDATE principals p SET ad_object_key = m.object_key, resolved_by = 'domain_name'
    FROM (
        SELECT DISTINCT ON (x.id) x.id, o.object_key
        FROM principals x
        LEFT JOIN (""" + DOMAIN_ALIASES + """) da ON da.src = split_part(lower(x.domain), '.', 1)
        JOIN ad_domains d ON COALESCE(da.dst, lower(x.domain)) IN (lower(d.netbios_name), lower(d.dns_name))
                          OR split_part(COALESCE(da.dst, lower(x.domain)), '.', 1) = lower(d.netbios_name)
        JOIN v_ad_objects o ON o.directory_id = d.directory_id AND lower(o.sam_account_name) = lower(x.name)
                           AND (d.nc_name IS NULL OR lower(o.dn) LIKE '%' || lower(d.nc_name))
        WHERE x.scope = 'domain' AND x.ad_object_key IS NULL
        ORDER BY x.id, o.object_key
    ) m
    WHERE p.id = m.id
"""

BY_UPN = """
    UPDATE principals p SET ad_object_key = u.object_key, resolved_by = 'upn'
    FROM ad_users u
    WHERE p.scope = 'domain' AND p.ad_object_key IS NULL
      AND lower(u.user_principal_name) = lower(p.name || '@' || p.domain)
"""

# A bare account name is only looked up in the domain of the host it was seen on.
# Matching it across all directories would tie "admin" on one network to an
# unrelated account, and with it a wrong tier.
BY_HOST_DOMAIN = """
    WITH host_domain AS (
        SELECT DISTINCT i.host_asset_id, lower(i.domain) AS domain FROM host_identities i WHERE i.domain IS NOT NULL
        UNION
        SELECT c.host_asset_id, lower(d.dns_name)
        FROM v_asset_correlation c JOIN ad_computers k ON k.id = c.ad_computer_id
        JOIN ad_domains d ON d.directory_id = k.directory_id AND lower(k.dn) LIKE '%' || lower(d.nc_name)
    ), found AS (
        SELECT p.id, min(o.object_key) AS object_key, count(DISTINCT o.object_key) AS n
        FROM principals p
        JOIN host_domain h ON h.host_asset_id = p.host_asset_id
        LEFT JOIN (""" + DOMAIN_ALIASES + """) da ON da.src = split_part(h.domain, '.', 1)
        JOIN ad_domains d ON COALESCE(da.dst, h.domain) IN (lower(d.dns_name), lower(d.netbios_name))
        JOIN v_ad_objects o ON o.directory_id = d.directory_id AND lower(o.sam_account_name) = lower(p.name)
                           AND (d.nc_name IS NULL OR lower(o.dn) LIKE '%' || lower(d.nc_name))
        WHERE p.scope = 'unknown' AND p.ad_object_key IS NULL
        GROUP BY p.id
    )
    UPDATE principals p
    SET ad_object_key = CASE WHEN f.n = 1 THEN f.object_key END,
        resolved_by = CASE WHEN f.n = 1 THEN 'host_domain' ELSE 'ambiguous' END
    FROM found f WHERE p.id = f.id
"""

ACCESS_DIRECT = r"""
    WITH facts AS (
        SELECT s.principal_id, s.host_asset_id, s.session_type AS access_type, 'usage' AS access_class, s.plugin_id,
               s.session_type AS evidence, o.last_seen, 1 AS rank
        FROM host_sessions s JOIN plugin_outputs o ON o.id = s.source_output_id
        UNION ALL
        SELECT s.principal_id, s.host_asset_id, 'service_account', 'usage', min(s.plugin_id),
               string_agg(DISTINCT s.service_name, ', '), max(o.last_seen), 2
        FROM host_services s JOIN plugin_outputs o ON o.id = s.source_output_id
        WHERE s.principal_id IS NOT NULL GROUP BY s.principal_id, s.host_asset_id
        UNION ALL
        SELECT g.principal_id, g.host_asset_id,
               CASE g.group_sid WHEN 'S-1-5-32-544' THEN 'local_admin' WHEN 'S-1-5-32-555' THEN 'rdp_right' ELSE 'psremote_right' END,
               'right', g.plugin_id, g.group_name, o.last_seen, CASE WHEN g.plugin_id = 71246 THEN 1 ELSE 2 END
        FROM host_group_members g JOIN plugin_outputs o ON o.id = g.source_output_id
        WHERE g.group_sid IN ('S-1-5-32-544', 'S-1-5-32-555', 'S-1-5-32-580')
        UNION ALL
        SELECT c.principal_id, c.host_asset_id, 'scan_account', 'scan', c.plugin_id, c.protocol, o.last_seen, 3
        FROM host_scan_coverage c JOIN plugin_outputs o ON o.id = c.source_output_id
        WHERE c.principal_id IS NOT NULL AND c.status = 'credentialed'
    )
    INSERT INTO identity_host_access (identity_key, identity_name, identity_kind, scope, principal_id, ad_object_key,
        privileged_hint, host_asset_id, access_type, access_class, evidence_plugin_id, evidence, confidence, last_seen)
    SELECT DISTINCT ON (COALESCE(p.ad_object_key, p.natural_key), f.host_asset_id, f.access_type)
           COALESCE(p.ad_object_key, p.natural_key),
           COALESCE(o.name, CASE WHEN p.domain IS NOT NULL THEN p.domain || '\' || p.name ELSE p.name END),
           CASE WHEN u.account_type IN ('service', 'gmsa') THEN 'service_account'
                WHEN o.object_type = 'group' THEN 'group' WHEN o.object_type = 'computer' THEN 'computer' ELSE p.kind END,
           p.scope, p.id, p.ad_object_key, p.privileged_hint, f.host_asset_id, f.access_type, f.access_class, f.plugin_id,
           f.evidence, CASE WHEN p.scope = 'unknown' AND p.ad_object_key IS NULL THEN 'low' ELSE 'high' END, f.last_seen
    FROM facts f
    JOIN principals p ON p.id = f.principal_id
    LEFT JOIN v_ad_objects o ON o.object_key = p.ad_object_key
    LEFT JOIN ad_users u ON u.object_key = p.ad_object_key
    WHERE p.scope <> 'builtin'
    ORDER BY COALESCE(p.ad_object_key, p.natural_key), f.host_asset_id, f.access_type, f.rank
"""

# A right held by an AD group reaches every member, directly or through nested groups.
ACCESS_GROUPS = """
    WITH RECURSIVE members(group_key, member_key, depth) AS (
        SELECT group_key, member_key, 1 FROM ad_group_members
        UNION
        SELECT m.group_key, g.member_key, m.depth + 1
        FROM members m JOIN ad_group_members g ON g.group_key = m.member_key
        WHERE m.depth < 10
    )
    INSERT INTO identity_host_access (identity_key, identity_name, identity_kind, scope, ad_object_key, host_asset_id,
        access_type, access_class, via_group_key, evidence_plugin_id, evidence, confidence, last_seen)
    SELECT DISTINCT ON (o.object_key, a.host_asset_id, a.access_type)
           o.object_key, o.name, CASE WHEN o.object_type = 'service_account' THEN 'service_account' ELSE o.object_type END,
           'domain', o.object_key, a.host_asset_id, a.access_type, 'right', a.ad_object_key, a.evidence_plugin_id,
           'via ' || a.identity_name, 'high', a.last_seen
    FROM identity_host_access a
    JOIN members m ON m.group_key = a.ad_object_key
    JOIN v_ad_objects o ON o.object_key = m.member_key AND o.object_type <> 'group'
    WHERE a.access_class = 'right' AND a.identity_kind = 'group'
    ORDER BY o.object_key, a.host_asset_id, a.access_type, m.depth
    ON CONFLICT (identity_key, host_asset_id, access_type) DO NOTHING
"""

EDGES = """
    INSERT INTO attack_edges (edge_type, source_key, target_key, host_asset_id, evidence_plugin_id, confidence, detail, last_seen)
    SELECT DISTINCT ON (e.edge_type, e.source_key, e.target_key)
           e.edge_type, e.source_key, e.target_key, e.host_asset_id, e.evidence_plugin_id, e.confidence, e.detail, e.last_seen
    FROM (
        SELECT CASE a.access_type WHEN 'local_admin' THEN 'AdminTo' WHEN 'rdp_right' THEN 'CanRDP' ELSE 'CanPSRemote' END AS edge_type,
               a.identity_key AS source_key, COALESCE(c.ad_object_key, 'host:' || a.host_asset_id) AS target_key,
               a.host_asset_id, a.evidence_plugin_id, a.confidence,
               jsonb_build_object('via_group', a.via_group_key) AS detail, a.last_seen
        FROM identity_host_access a JOIN v_asset_correlation c ON c.host_asset_id = a.host_asset_id
        WHERE a.access_type IN ('local_admin', 'rdp_right', 'psremote_right')
        UNION ALL
        SELECT CASE WHEN a.access_type = 'service_account' THEN 'RunsServiceAs' ELSE 'HasSession' END,
               COALESCE(c.ad_object_key, 'host:' || a.host_asset_id), a.identity_key, a.host_asset_id, a.evidence_plugin_id,
               a.confidence, jsonb_build_object('type', a.access_type, 'evidence', a.evidence), a.last_seen
        FROM identity_host_access a JOIN v_asset_correlation c ON c.host_asset_id = a.host_asset_id
        WHERE a.access_type IN ('session', 'last_logon', 'smb_session', 'service_account')
    ) e
    ORDER BY e.edge_type, e.source_key, e.target_key, e.confidence
"""


def correlate(session, log, job_id=None) -> dict:
    """All steps on `session`; the caller commits."""
    session.execute(text("REFRESH MATERIALIZED VIEW CONCURRENTLY host_ad_match"))
    session.execute(text("DELETE FROM identity_host_access"))
    orphans = session.execute(text(ORPHANS)).rowcount
    session.execute(text(RESET))
    resolved = {}
    for name, statement in (("alias", BY_ALIAS), ("sid", BY_SID), ("domain_name", BY_DOMAIN_NAME), ("upn", BY_UPN), ("host_domain", BY_HOST_DOMAIN)):
        resolved[name] = session.execute(text(statement)).rowcount
    log.info("principals_resolved", job_id=job_id, orphans_removed=orphans, **resolved)
    direct = session.execute(text(ACCESS_DIRECT)).rowcount
    via_groups = session.execute(text(ACCESS_GROUPS)).rowcount
    session.execute(text("DELETE FROM attack_edges"))
    edges = session.execute(text(EDGES)).rowcount
    # The read models behind the screens; readers keep the old rows until commit.
    for view in ("host_risk", "local_accounts", "software_summary", "dashboard_summary"):
        session.execute(text(f"REFRESH MATERIALIZED VIEW CONCURRENTLY {view}"))
    log.info("edges_built", job_id=job_id, access=direct, via_groups=via_groups, edges=edges)
    return {"resolved": resolved, "access": direct, "access_via_groups": via_groups, "edges": edges}


def run(job, log) -> dict:
    session = new_session()
    try:
        stats = correlate(session, log, job["id"])
        # New statistics, or the planner works from the sizes before this run.
        for table in ("host_risk", "local_accounts", "software_summary", "identity_host_access", "principals"):
            session.execute(text(f"ANALYZE {table}"))
        session.commit()
        return stats
    finally:
        session.close()
