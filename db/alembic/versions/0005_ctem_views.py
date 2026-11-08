"""CTEM: load the plugin catalog, extend asset correlation, add risk views

Revision ID: 0005
Revises: 0004
"""
import json
import pathlib

from alembic import op
import sqlalchemy as sa

revision = '0005'
down_revision = '0004'
branch_labels = None
depends_on = None

CATALOG = pathlib.Path(__file__).resolve().parents[2] / "data" / "plugin_catalog.json"

CORRELATION = r"""
    CREATE VIEW v_asset_correlation AS
    SELECT h.id AS host_asset_id, c.id AS ad_computer_id, c.object_key AS ad_object_key,
           c.sam_account_name AS ad_sam_account_name, c.dn AS ad_dn
    FROM host_assets h
    LEFT JOIN LATERAL (
        SELECT c.id, c.object_key, c.sam_account_name, c.dn
        FROM ad_computers c
        WHERE (h.dns_name IS NOT NULL AND lower(c.dns_host_name) = lower(h.dns_name))
           OR (h.dns_name IS NOT NULL
               AND lower(c.sam_account_name) = lower(split_part(h.dns_name, '.', 1)) || '$')
           OR (h.netbios_name IS NOT NULL
               AND lower(c.sam_account_name) = lower(regexp_replace(h.netbios_name, '^.*\\', '')) || '$')
           OR EXISTS (SELECT 1 FROM host_identities i
                      WHERE i.host_asset_id = h.id
                        AND (lower(c.dns_host_name) = lower(i.fqdn)
                             OR lower(c.dn) = lower(i.dn)
                             OR (i.domain IS NOT NULL AND lower(c.sam_account_name) = lower(i.sam_account_name))))
        ORDER BY c.id
        LIMIT 1
    ) c ON true
"""

HOST_ASSETS = r"""
    CREATE VIEW v_host_assets AS
    SELECT h.*,
           d.device_type,
           CASE WHEN d.device_type IS NOT NULL AND EXISTS (
                    SELECT 1 FROM regexp_split_to_table(d.device_type, '\s*,\s*') t(kind)
                    WHERE t.kind NOT IN ('general_purpose', 'general_server', 'hypervisor', ''))
                THEN 'iot' ELSE 'device' END AS asset_class,
           ac.ad_computer_id,
           ac.ad_object_key,
           ac.ad_sam_account_name,
           (ac.ad_computer_id IS NOT NULL) AS ad_matched,
           dom.domain AS domain_name,
           (ac.ad_computer_id IS NOT NULL OR dom.domain IS NOT NULL) AS domain_joined
    FROM host_assets h
    JOIN v_asset_correlation ac ON ac.host_asset_id = h.id
    LEFT JOIN LATERAL (
        SELECT i.domain FROM host_identities i
        WHERE i.host_asset_id = h.id AND i.domain IS NOT NULL
        ORDER BY i.plugin_id = 170631 DESC, i.id LIMIT 1
    ) dom ON true
    CROSS JOIN LATERAL (
        SELECT NULLIF(CASE WHEN jsonb_typeof(h.raw->'keyDrivers') = 'string'
                            AND left(h.raw->>'keyDrivers', 1) = '{'
                           THEN (h.raw->>'keyDrivers')::jsonb->>'device type' END, '') AS device_type
    ) d
"""

HOST_RISK = r"""
    CREATE MATERIALIZED VIEW host_risk AS
    SELECT h.id AS host_asset_id,
           COALESCE(h.dns_name, regexp_replace(h.netbios_name, '^.*\\', ''), host(h.ip)) AS host_name,
           host(h.ip) AS ip, h.os, h.acr_score, h.aes_score, h.ad_object_key,
           t.tier AS host_tier,
           COALESCE(v.critical, 0) AS critical, COALESCE(v.high, 0) AS high,
           v.max_vpr, COALESCE(v.exploitable, 0) AS exploitable,
           COALESCE(s.credentialed, false) AS credentialed
    FROM v_host_assets h
    LEFT JOIN LATERAL (
        SELECT count(*) FILTER (WHERE severity = 4) AS critical, count(*) FILTER (WHERE severity = 3) AS high,
               max(vpr_score) AS max_vpr, count(*) FILTER (WHERE exploit_available) AS exploitable
        FROM vulnerabilities x WHERE x.host_asset_id = h.id
    ) v ON true
    LEFT JOIN LATERAL (
        SELECT bool_or(status = 'credentialed') AS credentialed FROM host_scan_coverage c WHERE c.host_asset_id = h.id
    ) s ON true
    LEFT JOIN v_ad_object_tier t ON t.object_key = h.ad_object_key
"""


def upgrade():
    op.execute("DROP VIEW v_host_assets")
    op.execute("DROP VIEW v_asset_correlation")
    op.execute(CORRELATION)
    op.execute(HOST_ASSETS)
    op.execute(HOST_RISK)
    op.execute("CREATE UNIQUE INDEX ix_host_risk_host_asset_id ON host_risk (host_asset_id)")
    op.execute("CREATE INDEX ix_host_risk_critical ON host_risk (critical)")
    op.execute("CREATE INDEX ix_host_risk_acr_score ON host_risk (acr_score)")

    # Pipeline counters per plugin, for Settings > CTEM.
    op.execute("""
        CREATE VIEW v_ctem_pipeline AS
        SELECT c.plugin_id, c.name, c.scenario, c.extractor, c.review_status, c.enabled, c.sc_finding_count,
               c.last_collected_at,
               count(o.id) AS collected,
               count(o.id) FILTER (WHERE o.extract_status = 'pending') AS pending,
               count(o.id) FILTER (WHERE o.extract_status = 'ok') AS parsed,
               count(o.id) FILTER (WHERE o.extract_status = 'failed') AS failed,
               count(o.id) FILTER (WHERE o.truncated) AS truncated,
               count(o.id) FILTER (WHERE o.refers_to_attachment) AS refers_to_attachment,
               COALESCE(sum(o.records), 0) AS records,
               (SELECT count(*) FROM plugin_attachments a JOIN plugin_outputs ao ON ao.id = a.plugin_output_id
                WHERE ao.plugin_id = c.plugin_id) AS attachments
        FROM plugin_catalog c
        LEFT JOIN plugin_outputs o ON o.plugin_id = c.plugin_id
        WHERE c.scenario IS NOT NULL OR c.review_status = 'selected'
        GROUP BY c.plugin_id
    """)

    # One row per local account with whether it is privileged on its host.
    op.execute("""
        CREATE VIEW v_local_accounts AS
        SELECT DISTINCT ON (a.host_asset_id, a.principal_id)
               a.host_asset_id, a.principal_id, p.name, a.account_type, a.disabled,
               (EXISTS (SELECT 1 FROM host_group_members g
                        WHERE g.host_asset_id = a.host_asset_id AND g.principal_id = a.principal_id
                          AND g.group_sid = 'S-1-5-32-544')
                OR a.groups && ARRAY['sudo', 'wheel', 'admin', 'root']
                OR p.name = 'root'
                OR a.rid = 500) AS privileged
        FROM host_local_accounts a
        JOIN principals p ON p.id = a.principal_id
        WHERE a.account_type = 'user'
        ORDER BY a.host_asset_id, a.principal_id, a.plugin_id
    """)

    rows = json.loads(CATALOG.read_text(encoding="utf-8"))
    table = sa.table("plugin_catalog", *[sa.column(c) for c in rows[0]])
    op.bulk_insert(table, rows)


def downgrade():
    op.execute("DELETE FROM plugin_catalog")
    op.execute("DROP VIEW IF EXISTS v_local_accounts")
    op.execute("DROP VIEW IF EXISTS v_ctem_pipeline")
    op.execute("DROP MATERIALIZED VIEW IF EXISTS host_risk")
    op.execute("DROP VIEW IF EXISTS v_host_assets")
    op.execute("DROP VIEW IF EXISTS v_asset_correlation")
