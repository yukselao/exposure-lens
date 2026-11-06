"""Asset views: host class (device / iot) and the AD computer each host maps to

Revision ID: 0003
Revises: 0002
"""
from alembic import op

revision = '0003'
down_revision = '0002'
branch_labels = None
depends_on = None


def upgrade():
    op.execute("CREATE INDEX ix_ad_computers_dns_host_name_lower ON ad_computers (lower(dns_host_name))")
    op.execute("CREATE INDEX ix_vulnerabilities_host_asset_id_severity ON vulnerabilities (host_asset_id, severity)")

    # A host is domain joined when an AD computer has the same name. Checked in
    # this order: full DNS name, short DNS name, NetBIOS name (DOMAIN\\HOST).
    op.execute("""
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
                   AND lower(c.sam_account_name) = lower(regexp_replace(h.netbios_name, '^.*\\\\', '')) || '$')
            ORDER BY c.id
            LIMIT 1
        ) c ON true
    """)

    # asset_class: 'iot' when Security Center reports a device type other than
    # a general purpose computer, server or hypervisor (switch, router, camera,
    # printer, embedded, ...); otherwise 'device'.
    op.execute("""
        CREATE VIEW v_host_assets AS
        SELECT h.*,
               d.device_type,
               CASE WHEN d.device_type IS NOT NULL AND EXISTS (
                        SELECT 1 FROM regexp_split_to_table(d.device_type, '\\s*,\\s*') t(kind)
                        WHERE t.kind NOT IN ('general_purpose', 'general_server', 'hypervisor', ''))
                    THEN 'iot' ELSE 'device' END AS asset_class,
               ac.ad_computer_id,
               ac.ad_object_key,
               ac.ad_sam_account_name,
               (ac.ad_computer_id IS NOT NULL) AS domain_joined
        FROM host_assets h
        JOIN v_asset_correlation ac ON ac.host_asset_id = h.id
        CROSS JOIN LATERAL (
            SELECT NULLIF(CASE WHEN jsonb_typeof(h.raw->'keyDrivers') = 'string'
                                AND left(h.raw->>'keyDrivers', 1) = '{'
                               THEN (h.raw->>'keyDrivers')::jsonb->>'device type' END, '') AS device_type
        ) d
    """)


def downgrade():
    op.execute("DROP VIEW IF EXISTS v_host_assets")
    op.execute("DROP VIEW IF EXISTS v_asset_correlation")
    op.execute("DROP INDEX IF EXISTS ix_vulnerabilities_host_asset_id_severity")
    op.execute("DROP INDEX IF EXISTS ix_ad_computers_dns_host_name_lower")
