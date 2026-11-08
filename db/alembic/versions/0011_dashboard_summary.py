"""Dashboard read model: one row, computed by ctem_correlate

Revision ID: 0011
Revises: 0010
"""
from alembic import op

revision = '0011'
down_revision = '0010'
branch_labels = None
depends_on = None


def upgrade():
    op.execute("""
        CREATE MATERIALIZED VIEW dashboard_summary AS
        SELECT 1 AS id, jsonb_build_object(
            'ad', (SELECT jsonb_build_object(
                'users', count(*) FILTER (WHERE account_type = 'user'),
                'users_disabled', count(*) FILTER (WHERE account_type = 'user' AND enabled IS FALSE),
                'service_accounts', count(*) FILTER (WHERE account_type <> 'user'),
                'groups', (SELECT count(*) FROM ad_groups),
                'computers', (SELECT count(*) FROM ad_computers),
                'group_policies', (SELECT count(*) FROM ad_group_policies)) FROM ad_users),
            'tiers', COALESCE((SELECT jsonb_agg(x ORDER BY x.tier) FROM (
                SELECT t.tier, o.object_type, count(*) AS n
                FROM v_ad_object_tier t JOIN v_ad_objects o ON o.object_key = t.object_key
                WHERE t.tier IS NOT NULL GROUP BY t.tier, o.object_type) x), '[]'),
            'severity', COALESCE((SELECT jsonb_agg(x ORDER BY x.severity DESC) FROM (
                SELECT severity, count(*) AS n, round(avg(EXTRACT(EPOCH FROM now() - first_seen) / 86400))::int AS avg_age_days
                FROM vulnerabilities GROUP BY severity) x), '[]'),
            'top_vpr', COALESCE((SELECT jsonb_agg(x) FROM (
                SELECT plugin_id, min(plugin_name) AS plugin_name, max(vpr_score) AS vpr_score, max(severity) AS severity,
                       count(DISTINCT host_asset_id) AS hosts, bool_or(exploit_available) AS exploit_available
                FROM vulnerabilities WHERE vpr_score >= 9
                GROUP BY plugin_id ORDER BY max(vpr_score) DESC, count(DISTINCT host_asset_id) DESC, plugin_id LIMIT 10) x), '[]'),
            'by_query', COALESCE((SELECT jsonb_agg(x) FROM (
                SELECT q.id AS query_id, p.name AS pool, q.name AS query, q.last_sync_at,
                       count(v.id) AS total, count(DISTINCT v.host_asset_id) AS hosts,
                       count(*) FILTER (WHERE v.severity = 4) AS critical, count(*) FILTER (WHERE v.severity = 3) AS high,
                       count(*) FILTER (WHERE v.severity = 2) AS medium, count(*) FILTER (WHERE v.severity = 1) AS low,
                       count(*) FILTER (WHERE v.severity = 0) AS info
                FROM sc_queries q JOIN sc_pools p ON p.id = q.pool_id
                LEFT JOIN sc_query_findings f ON f.query_id = q.id
                LEFT JOIN vulnerabilities v ON v.id = f.vulnerability_id
                GROUP BY q.id, p.name, q.name, q.last_sync_at ORDER BY count(v.id) DESC, q.id LIMIT 12) x), '[]'),
            'totals', (SELECT jsonb_build_object(
                'findings', count(*), 'vpr9', count(*) FILTER (WHERE vpr_score >= 9),
                'exploitable', count(*) FILTER (WHERE exploit_available),
                'finding_hosts', count(DISTINCT host_asset_id),
                'hosts', (SELECT count(*) FROM host_assets),
                'pools', (SELECT count(*) FROM sc_pools), 'queries', (SELECT count(*) FROM sc_queries)) FROM vulnerabilities)
        ) AS data
    """)
    op.execute("CREATE UNIQUE INDEX ix_dashboard_summary_id ON dashboard_summary (id)")


def downgrade():
    op.execute("DROP MATERIALIZED VIEW IF EXISTS dashboard_summary")
