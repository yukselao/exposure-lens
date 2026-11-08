"""Compute the host to AD computer match once per correlation, not on every read

Revision ID: 0007
Revises: 0006
"""
from alembic import op

revision = '0007'
down_revision = '0006'
branch_labels = None
depends_on = None


def upgrade():
    op.execute(r"""
        CREATE MATERIALIZED VIEW host_ad_match AS
        WITH names AS (
            SELECT id AS host_asset_id, 1 AS rank, lower(dns_name) AS fqdn, NULL::text AS sam, NULL::text AS dn
            FROM host_assets WHERE dns_name IS NOT NULL
            UNION ALL
            SELECT id, 2, NULL, lower(split_part(dns_name, '.', 1)) || '$', NULL FROM host_assets WHERE dns_name IS NOT NULL
            UNION ALL
            SELECT id, 3, NULL, lower(regexp_replace(netbios_name, '^.*\\', '')) || '$', NULL FROM host_assets WHERE netbios_name IS NOT NULL
            UNION ALL
            SELECT host_asset_id, 4, lower(fqdn), NULL, NULL FROM host_identities WHERE fqdn IS NOT NULL
            UNION ALL
            SELECT host_asset_id, 5, NULL, NULL, lower(dn) FROM host_identities WHERE dn IS NOT NULL
            UNION ALL
            SELECT host_asset_id, 6, NULL, lower(sam_account_name), NULL FROM host_identities WHERE domain IS NOT NULL AND sam_account_name IS NOT NULL
        )
        SELECT DISTINCT ON (n.host_asset_id) n.host_asset_id, c.id AS ad_computer_id, c.object_key AS ad_object_key,
               c.sam_account_name AS ad_sam_account_name, c.dn AS ad_dn
        FROM names n
        JOIN ad_computers c ON lower(c.dns_host_name) = n.fqdn OR lower(c.sam_account_name) = n.sam OR lower(c.dn) = n.dn
        ORDER BY n.host_asset_id, n.rank, c.id
    """)
    op.execute("CREATE UNIQUE INDEX ix_host_ad_match_host_asset_id ON host_ad_match (host_asset_id)")
    op.execute("""
        CREATE OR REPLACE VIEW v_asset_correlation AS
        SELECT h.id AS host_asset_id, m.ad_computer_id, m.ad_object_key, m.ad_sam_account_name, m.ad_dn
        FROM host_assets h LEFT JOIN host_ad_match m ON m.host_asset_id = h.id
    """)


def downgrade():
    raise NotImplementedError("Restore the view from revision 0005 by hand.")
