"""AD views: all objects in one relation, and the effective tier per object

Revision ID: 0002
Revises: 0001
"""
from alembic import op

revision = '0002'
down_revision = '0001'
branch_labels = None
depends_on = None

COMMON = ("id, directory_id, tie_id, object_id, object_key, object_guid, sid, dn, "
          "sam_account_name, name, when_created, when_changed, synced_at")


def upgrade():
    op.execute(f"""
        CREATE VIEW v_ad_objects AS
        SELECT CASE account_type WHEN 'user' THEN 'user' ELSE 'service_account' END AS object_type,
               {COMMON}, enabled FROM ad_users
        UNION ALL SELECT 'group', {COMMON}, NULL::boolean FROM ad_groups
        UNION ALL SELECT 'computer', {COMMON}, enabled FROM ad_computers
        UNION ALL SELECT 'group_policy', {COMMON}, NULL::boolean FROM ad_group_policies
    """)
    # A manual assignment wins over the one written by the TIE sync. A manual
    # row with a NULL tier removes the object from every tier.
    op.execute("""
        CREATE VIEW v_ad_object_tier AS
        SELECT DISTINCT ON (object_key) object_key, tier, source, name, object_type, detail, updated_at
        FROM ad_tier_assignments
        ORDER BY object_key, (source = 'manual') DESC
    """)


def downgrade():
    op.execute("DROP VIEW IF EXISTS v_ad_object_tier")
    op.execute("DROP VIEW IF EXISTS v_ad_objects")
