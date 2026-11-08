"""One row per local account and host, merged from account and group plugins

Revision ID: 0008
Revises: 0007
"""
from alembic import op

revision = '0008'
down_revision = '0007'
branch_labels = None
depends_on = None


def upgrade():
    op.execute("DROP VIEW v_local_accounts")
    op.execute("""
        CREATE VIEW v_local_accounts AS
        WITH base AS (
            SELECT host_asset_id, principal_id, rid, account_type, disabled, flags, groups, shell, last_seen, plugin_id,
                   false AS in_admin_group
            FROM host_local_accounts
            UNION ALL
            SELECT g.host_asset_id, g.principal_id, NULL, 'user', NULL, '{}'::jsonb, ARRAY[g.group_name::text], NULL, g.last_seen,
                   g.plugin_id, g.group_sid = 'S-1-5-32-544'
            FROM host_group_members g
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
                   bool_or(in_admin_group) AS in_admin_group, array_agg(DISTINCT plugin_id) AS plugins
            FROM base GROUP BY 1, 2
        )
        SELECT m.host_asset_id, m.principal_id, p.name, m.rid, m.account_type, m.disabled, m.shell, m.last_seen, m.plugins,
               COALESCE(g.groups, '{}') AS groups, COALESCE(f.flags, '{}') AS flags,
               (m.in_admin_group OR m.rid = 500 OR p.name = 'root'
                OR COALESCE(g.groups, '{}') && ARRAY['sudo', 'wheel', 'admin', 'root']) IS TRUE AS privileged
        FROM merged m
        JOIN principals p ON p.id = m.principal_id
        LEFT JOIN merged_groups g ON g.host_asset_id = m.host_asset_id AND g.principal_id = m.principal_id
        LEFT JOIN merged_flags f ON f.host_asset_id = m.host_asset_id AND f.principal_id = m.principal_id
    """)


def downgrade():
    raise NotImplementedError("Restore the view from revision 0005 by hand.")
