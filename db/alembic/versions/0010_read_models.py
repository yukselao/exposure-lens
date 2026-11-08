"""Read models for the UI: everything a screen aggregates is computed once per
correlation, in materialized views with their own indexes

Revision ID: 0010
Revises: 0009
"""
from alembic import op

revision = '0010'
down_revision = '0009'
branch_labels = None
depends_on = None

HOST_RISK = r"""
    CREATE MATERIALIZED VIEW host_risk AS
    WITH findings AS (
        SELECT host_asset_id, count(*) FILTER (WHERE severity = 4) AS critical, count(*) FILTER (WHERE severity = 3) AS high,
               count(*) FILTER (WHERE severity = 2) AS medium, count(*) FILTER (WHERE severity = 1) AS low,
               max(vpr_score) AS max_vpr, count(*) FILTER (WHERE exploit_available) AS exploitable
        FROM vulnerabilities GROUP BY host_asset_id
    ), coverage AS (
        SELECT host_asset_id, bool_or(status = 'credentialed') AS credentialed FROM host_scan_coverage GROUP BY host_asset_id
    )
    SELECT h.id AS host_asset_id,
           COALESCE(h.dns_name, regexp_replace(h.netbios_name, '^.*\\', ''), host(h.ip)) AS host_name,
           host(h.ip) AS ip, h.os, h.acr_score, h.aes_score, h.ad_object_key, h.ad_computer_id, h.repository_name,
           h.asset_class, h.device_type, h.domain_joined, h.ad_matched, h.domain_name, h.last_seen,
           t.tier AS host_tier,
           COALESCE(v.critical, 0) AS critical, COALESCE(v.high, 0) AS high, COALESCE(v.medium, 0) AS medium, COALESCE(v.low, 0) AS low,
           v.max_vpr, COALESCE(v.exploitable, 0) AS exploitable, COALESCE(c.credentialed, false) AS credentialed
    FROM v_host_assets h
    LEFT JOIN findings v ON v.host_asset_id = h.id
    LEFT JOIN coverage c ON c.host_asset_id = h.id
    LEFT JOIN v_ad_object_tier t ON t.object_key = h.ad_object_key
"""

RISK_ACCESS = """
    CREATE VIEW v_risk_access AS
    SELECT a.id, a.identity_key, a.identity_name, a.identity_kind, a.scope, a.ad_object_key, a.privileged_hint,
           a.host_asset_id, a.access_type, a.access_class, a.via_group_key, a.evidence_plugin_id, a.evidence,
           a.confidence, a.last_seen,
           t.tier,
           (t.tier = 0 OR a.privileged_hint) IS TRUE AS tier0_like,
           h.host_name, h.ip, h.os, h.acr_score, h.aes_score, h.host_tier, h.critical, h.high, h.max_vpr,
           h.exploitable, h.credentialed
    FROM identity_host_access a
    JOIN host_risk h ON h.host_asset_id = a.host_asset_id
    LEFT JOIN v_ad_object_tier t ON t.object_key = a.ad_object_key
"""

LOCAL_ACCOUNTS = """
    CREATE MATERIALIZED VIEW local_accounts AS
    WITH base AS (
        SELECT a.host_asset_id, a.principal_id, a.rid, a.account_type, a.disabled, a.flags, a.groups, a.shell, o.last_seen, a.plugin_id,
               false AS in_admin_group
        FROM host_local_accounts a JOIN plugin_outputs o ON o.id = a.source_output_id
        UNION ALL
        SELECT g.host_asset_id, g.principal_id, NULL, 'user', NULL, '{}'::jsonb, ARRAY[g.group_name::text], NULL, o.last_seen,
               g.plugin_id, g.group_sid = 'S-1-5-32-544'
        FROM host_group_members g
        JOIN plugin_outputs o ON o.id = g.source_output_id
        JOIN principals p ON p.id = g.principal_id
        WHERE p.scope = 'local' AND p.kind <> 'group'
    ), merged_groups AS (
        SELECT b.host_asset_id, b.principal_id, array_agg(DISTINCT x) AS groups
        FROM base b CROSS JOIN LATERAL unnest(b.groups) AS x GROUP BY 1, 2
    ), merged_flags AS (
        SELECT b.host_asset_id, b.principal_id, array_agg(DISTINCT k) AS flags
        FROM base b CROSS JOIN LATERAL jsonb_object_keys(b.flags) AS k GROUP BY 1, 2
    ), merged AS (
        SELECT host_asset_id, principal_id, max(rid) AS rid,
               CASE WHEN bool_or(account_type = 'user') THEN 'user' ELSE 'system' END AS account_type,
               bool_or(disabled) AS disabled, max(shell) AS shell, max(last_seen) AS last_seen,
               bool_or(in_admin_group) AS in_admin_group
        FROM base GROUP BY 1, 2
    )
    SELECT m.host_asset_id, m.principal_id, p.name, lower(p.name) AS name_norm, m.rid, m.account_type, m.disabled, m.shell, m.last_seen,
           COALESCE(g.groups, '{}') AS groups, COALESCE(f.flags, '{}') AS flags,
           (m.in_admin_group OR m.rid = 500 OR p.name = 'root'
            OR COALESCE(g.groups, '{}') && ARRAY['sudo', 'wheel', 'admin', 'root']) IS TRUE AS privileged
    FROM merged m
    JOIN principals p ON p.id = m.principal_id
    LEFT JOIN merged_groups g ON g.host_asset_id = m.host_asset_id AND g.principal_id = m.principal_id
    LEFT JOIN merged_flags f ON f.host_asset_id = m.host_asset_id AND f.principal_id = m.principal_id
"""

SOFTWARE_SUMMARY = """
    CREATE MATERIALIZED VIEW software_summary AS
    SELECT s.name_norm, min(s.name) AS name, count(DISTINCT s.version) AS versions, max(s.version) AS latest_version,
           count(DISTINCT hs.host_asset_id) AS hosts, max(hs.installed_at) AS last_installed, min(hs.source_type) AS source_type
    FROM host_software hs JOIN software s ON s.id = hs.software_id
    GROUP BY s.name_norm
"""


def upgrade():
    op.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm")
    op.execute("DROP VIEW v_risk_access")
    op.execute("DROP MATERIALIZED VIEW host_risk")
    op.execute("DROP VIEW v_local_accounts")
    op.execute(HOST_RISK)
    op.execute("CREATE UNIQUE INDEX ix_host_risk_host_asset_id ON host_risk (host_asset_id)")
    op.execute("CREATE INDEX ix_host_risk_critical ON host_risk (critical)")
    op.execute("CREATE INDEX ix_host_risk_acr_score ON host_risk (acr_score)")
    op.execute("CREATE INDEX ix_host_risk_asset_class_critical ON host_risk (asset_class, critical DESC)")
    op.execute("CREATE INDEX ix_host_risk_host_name_lower ON host_risk (lower(host_name))")
    op.execute(RISK_ACCESS)
    op.execute(LOCAL_ACCOUNTS)
    op.execute("CREATE UNIQUE INDEX ix_local_accounts_host_principal ON local_accounts (host_asset_id, principal_id)")
    op.execute("CREATE INDEX ix_local_accounts_name_norm ON local_accounts (name_norm)")
    op.execute("CREATE INDEX ix_local_accounts_type_privileged ON local_accounts (account_type, privileged)")
    op.execute("CREATE INDEX ix_local_accounts_groups ON local_accounts USING gin (groups)")
    op.execute("CREATE INDEX ix_local_accounts_flags ON local_accounts USING gin (flags)")
    op.execute(SOFTWARE_SUMMARY)
    op.execute("CREATE UNIQUE INDEX ix_software_summary_name_norm ON software_summary (name_norm)")
    op.execute("CREATE INDEX ix_software_summary_hosts ON software_summary (hosts DESC, name_norm)")
    op.execute("CREATE INDEX ix_software_summary_name_trgm ON software_summary USING gin (name_norm gin_trgm_ops)")

    # Foreign keys without an index: a host delete scanned these tables.
    op.create_index("ix_attack_edges_host_asset_id", "attack_edges", ["host_asset_id"])
    op.create_index("ix_principals_host_asset_id", "principals", ["host_asset_id"])
    op.create_index("ix_identity_host_access_principal_id", "identity_host_access", ["principal_id"])
    # The latest job of a kind is read on every poll; sync_jobs only grows.
    op.create_index("ix_sync_jobs_connector_kind_id", "sync_jobs", ["connector", "kind", "id"])
    op.execute("CREATE INDEX ix_host_software_installed_at ON host_software (installed_at) WHERE installed_at IS NOT NULL")
    op.execute("CREATE INDEX ix_vulnerabilities_top_vpr ON vulnerabilities (plugin_id, host_asset_id) INCLUDE (vpr_score, severity, exploit_available) WHERE vpr_score >= 9")
    for table in ("ad_users", "ad_groups", "ad_computers", "ad_group_policies"):
        op.execute(f"CREATE INDEX ix_{table}_name_lower ON {table} (lower(name), id)")
    # Replaced by ix_vulnerabilities_top_vpr; nothing else filters on plugin alone or on CVE yet.
    op.execute("DROP INDEX ix_vulnerabilities_plugin_id")


def downgrade():
    raise NotImplementedError("Restore the views from revision 0009 by hand.")
