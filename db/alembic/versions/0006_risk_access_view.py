"""Risk screen: one relation joining access facts, host risk and tiers

Revision ID: 0006
Revises: 0005
"""
from alembic import op

revision = '0006'
down_revision = '0005'
branch_labels = None
depends_on = None


def upgrade():
    op.execute("""
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
    """)


def downgrade():
    op.execute("DROP VIEW IF EXISTS v_risk_access")
