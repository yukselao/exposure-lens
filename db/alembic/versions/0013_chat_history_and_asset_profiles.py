"""Agent Tenable chat history and the central asset table

Revision ID: 0013
Revises: 0012
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects.postgresql import JSONB

revision = '0013'
down_revision = '0012'
branch_labels = None
depends_on = None


def upgrade():
    op.create_table(
        "ai_conversations",
        sa.Column("id", sa.BigInteger, sa.Identity(), primary_key=True),
        sa.Column("title", sa.String(200), nullable=False),
        sa.Column("skill", sa.String(64)),
        sa.Column("messages", JSONB, nullable=False, server_default=sa.text("'[]'::jsonb")),
        sa.Column("message_count", sa.Integer, nullable=False, server_default=sa.text("0")),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_ai_conversations_updated_at", "ai_conversations", ["updated_at"])

    op.create_table(
        "asset_profiles",
        sa.Column("id", sa.BigInteger, sa.Identity(), primary_key=True),
        sa.Column("asset_kind", sa.String(16), nullable=False),
        sa.Column("asset_key", sa.String(600), nullable=False),
        sa.Column("name", sa.Text, nullable=False),
        sa.Column("subtitle", sa.Text),
        sa.Column("ad_object_key", sa.String(255)),
        sa.Column("host_asset_id", sa.BigInteger),
        sa.Column("tier", sa.SmallInteger),
        sa.Column("risk_score", sa.Float),
        sa.Column("profile", JSONB, nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("systems", JSONB, nullable=False, server_default=sa.text("'[]'::jsonb")),
        sa.Column("vulnerabilities", JSONB, nullable=False, server_default=sa.text("'[]'::jsonb")),
        sa.Column("risk", JSONB, nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("summary", JSONB, nullable=False, server_default=sa.text("'{}'::jsonb")),
        sa.Column("source", sa.String(16), nullable=False, server_default=sa.text("'manual'")),
        sa.Column("sync_ms", sa.Integer),
        sa.Column("synced_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint("asset_kind", "asset_key", name="uq_asset_profiles_asset_kind_asset_key"),
    )
    op.create_index("ix_asset_profiles_synced_at", "asset_profiles", ["synced_at"])


def downgrade():
    op.drop_table("asset_profiles")
    op.drop_table("ai_conversations")
