"""Agent Tenable: knowledge packs and the indexes its tools search by

Revision ID: 0012
Revises: 0011
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects.postgresql import JSONB

revision = '0012'
down_revision = '0011'
branch_labels = None
depends_on = None

# "DOMAIN\name" -> "name"; a bare name stays as it is. Used to find the systems
# an account reaches when the identity has no AD match.
IDENTITY_SHORT = r"lower(CASE WHEN position('\' IN identity_name) > 0 THEN split_part(identity_name, '\', 2) ELSE identity_name END)"


def upgrade():
    op.create_table(
        "ai_packs",
        sa.Column("id", sa.BigInteger, primary_key=True),
        sa.Column("name", sa.String(200), nullable=False),
        sa.Column("version", sa.String(64)),
        sa.Column("description", sa.Text),
        sa.Column("file_name", sa.String(255), nullable=False),
        sa.Column("sha256", sa.String(64), nullable=False, unique=True),
        sa.Column("size_bytes", sa.BigInteger, nullable=False),
        sa.Column("manifest", JSONB, nullable=False, server_default="{}"),
        sa.Column("active", sa.Boolean, nullable=False, server_default=sa.text("false")),
        sa.Column("uploaded_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )

    # Identity lookups by UPN, display name and mail (mail lives in the attributes).
    op.execute("CREATE INDEX ix_ad_users_upn_lower ON ad_users (lower(user_principal_name))")
    op.execute("CREATE INDEX ix_ad_users_display_name_lower ON ad_users (lower(display_name))")
    op.execute("CREATE INDEX ix_ad_users_mail_lower ON ad_users (lower(attributes->>'mail'))")
    # Contains-searches ("find everyone called eid") on the four name fields.
    op.execute("CREATE INDEX ix_ad_users_search_trgm ON ad_users USING gin ("
               "(lower(coalesce(sam_account_name, '') || ' ' || coalesce(display_name, '') || ' ' || coalesce(name, '') || ' ' "
               "|| coalesce(user_principal_name, '') || ' ' || coalesce(attributes->>'mail', ''))) gin_trgm_ops)")
    # ad_groups already has ix_ad_groups_name_lower (0010) and ix_ad_groups_sam_account_name_lower (0003).
    op.execute("CREATE INDEX ix_ad_groups_search_trgm ON ad_groups USING gin ((lower(coalesce(sam_account_name, '') || ' ' || coalesce(name, ''))) gin_trgm_ops)")

    op.execute(f"CREATE INDEX ix_identity_host_access_short_name ON identity_host_access ({IDENTITY_SHORT})")
    op.execute("CREATE INDEX ix_identity_host_access_name_lower ON identity_host_access (lower(identity_name))")

    # Vulnerabilities of one host sorted the way the tools sort them.
    op.execute("CREATE INDEX ix_vulnerabilities_host_sev_vpr ON vulnerabilities (host_asset_id, severity DESC, vpr_score DESC NULLS LAST)")
    op.execute("CREATE INDEX ix_vulnerabilities_plugin_id ON vulnerabilities (plugin_id)")
    op.execute("CREATE INDEX ix_vulnerabilities_plugin_name_trgm ON vulnerabilities USING gin (lower(plugin_name) gin_trgm_ops)")
    # Class-C groupings and CIDR filters on the hosts.
    op.execute("CREATE INDEX ix_host_assets_ip_block ON host_assets ((set_masklen(network(ip), 24)))")
    op.execute("CREATE INDEX ix_host_risk_ip_inet ON host_risk ((CAST(ip AS inet)))")
    op.execute("CREATE INDEX ix_host_risk_search_trgm ON host_risk USING gin ((lower(coalesce(host_name, '') || ' ' || coalesce(ip, ''))) gin_trgm_ops)")


def downgrade():
    for name in ["ix_host_risk_search_trgm", "ix_host_risk_ip_inet", "ix_host_assets_ip_block", "ix_vulnerabilities_plugin_name_trgm",
                 "ix_vulnerabilities_plugin_id", "ix_vulnerabilities_host_sev_vpr", "ix_identity_host_access_name_lower",
                 "ix_identity_host_access_short_name", "ix_ad_groups_search_trgm",
                 "ix_ad_users_search_trgm", "ix_ad_users_mail_lower", "ix_ad_users_display_name_lower", "ix_ad_users_upn_lower"]:
        op.execute(f"DROP INDEX IF EXISTS {name}")
    op.drop_table("ai_packs")
