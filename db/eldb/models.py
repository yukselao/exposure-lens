"""Schema for Exposure Lens. This file is the single source of truth; Alembic
revisions under db/alembic/versions are generated from it."""
from sqlalchemy import (
    BigInteger, Boolean, Column, DateTime, Float, ForeignKey, Identity, Index,
    Integer, SmallInteger, String, Text, UniqueConstraint, func, text,
)
from sqlalchemy.dialects.postgresql import ARRAY, INET, JSONB
from sqlalchemy.orm import declared_attr

from .database import Base


def _now():
    return Column(DateTime(timezone=True), server_default=func.now(), nullable=False)


def _jsonb(default="{}"):
    return Column(JSONB, server_default=text(f"'{default}'::jsonb"), nullable=False)


# ---- shared -----------------------------------------------------------------
class Setting(Base):
    """One row per settings section (seccenter, seccenter_meta, tie). Secret
    values are stored encrypted under keys ending in `_enc`."""
    __tablename__ = "settings"
    section = Column(String(64), primary_key=True)
    data = _jsonb()
    updated_at = _now()


class SyncJob(Base):
    """Work queue between the web UI and the connectors. The UI (or the
    connector's own scheduler) inserts a `queued` row; the connector claims it."""
    __tablename__ = "sync_jobs"
    id = Column(BigInteger, Identity(), primary_key=True)
    connector = Column(String(32), nullable=False)            # sc | tie_postgres
    kind = Column(String(32), nullable=False, server_default="sync")  # sync | test_connection
    target_id = Column(Integer)                               # sc_pools.id for SC syncs
    trigger = Column(String(16), nullable=False, server_default="manual")  # manual | schedule
    status = Column(String(16), nullable=False, server_default="queued")   # queued | running | succeeded | failed
    requested_at = _now()
    started_at = Column(DateTime(timezone=True))
    finished_at = Column(DateTime(timezone=True))
    heartbeat_at = Column(DateTime(timezone=True))
    progress = _jsonb()
    stats = _jsonb()
    error = Column(Text)
    __table_args__ = (
        Index("ix_sync_jobs_connector_status", "connector", "status"),
        Index("ix_sync_jobs_connector_target_id", "connector", "target_id", "id"),
    )


# ---- Security Center ----------------------------------------------------------
class ScPool(Base):
    """A named group of SC queries that are synced together."""
    __tablename__ = "sc_pools"
    id = Column(Integer, Identity(), primary_key=True)
    name = Column(String(200), nullable=False, unique=True)
    description = Column(Text, nullable=False, server_default="")
    enabled = Column(Boolean, nullable=False, server_default=text("true"))
    sync_interval_minutes = Column(Integer, nullable=False, server_default="0")  # 0 = manual only
    last_sync_at = Column(DateTime(timezone=True))
    created_at = _now()
    updated_at = _now()


class ScQuery(Base):
    """One cumulative vulnerability query. `filters` holds the filter
    parameters (severity, ip_range, plugin_ids, score ranges, families, ...)."""
    __tablename__ = "sc_queries"
    id = Column(Integer, Identity(), primary_key=True)
    pool_id = Column(Integer, ForeignKey("sc_pools.id", ondelete="CASCADE"), nullable=False, index=True)
    name = Column(String(200), nullable=False)
    filters = _jsonb()
    max_records = Column(Integer, nullable=False, server_default="0")  # 0 = no limit
    enabled = Column(Boolean, nullable=False, server_default=text("true"))
    last_sync_at = Column(DateTime(timezone=True))
    last_total = Column(Integer)      # totalRecords reported by SC
    last_fetched = Column(Integer)    # rows written by the last sync
    created_at = _now()
    updated_at = _now()
    __table_args__ = (UniqueConstraint("pool_id", "name"),)


class HostAsset(Base):
    """A host seen in SC findings. `host_key` is the SC hostUUID when the
    repository provides one, otherwise the IP address."""
    __tablename__ = "host_assets"
    id = Column(BigInteger, Identity(), primary_key=True)
    repository_id = Column(Integer, nullable=False)
    repository_name = Column(String(255), nullable=False, server_default="")
    host_key = Column(String(255), nullable=False)
    host_uuid = Column(String(64))
    agent_uuid = Column(String(64))
    ip = Column(INET)
    dns_name = Column(String(255))
    netbios_name = Column(String(255))
    mac_address = Column(String(64))
    os = Column(Text)
    acr_score = Column(Float)
    aes_score = Column(Float)
    first_seen = Column(DateTime(timezone=True))
    last_seen = Column(DateTime(timezone=True))
    raw = _jsonb()
    synced_at = _now()
    __table_args__ = (
        UniqueConstraint("repository_id", "host_key"),
        Index("ix_host_assets_ip", "ip"),
        Index("ix_host_assets_dns_name_lower", text("lower(dns_name)")),
    )


class Vulnerability(Base):
    """A cumulative SC finding: one plugin on one host, port and protocol.
    Key fields are columns; the complete SC record is kept in `raw`."""
    __tablename__ = "vulnerabilities"
    id = Column(BigInteger, Identity(), primary_key=True)
    host_asset_id = Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"), nullable=False)
    plugin_id = Column(Integer, nullable=False)
    port = Column(Integer, nullable=False, server_default="0")
    protocol = Column(String(16), nullable=False, server_default="")
    vuln_uuid = Column(String(64))
    plugin_name = Column(Text, nullable=False, server_default="")
    family_id = Column(Integer)
    family_name = Column(String(255))
    severity = Column(SmallInteger, nullable=False, server_default="0")  # 0 Info .. 4 Critical
    vpr_score = Column(Float)
    cvss_v3 = Column(Float)
    cvss_v2 = Column(Float)
    epss_score = Column(Float)
    cve = Column(ARRAY(Text), nullable=False, server_default=text("'{}'::text[]"))
    exploit_available = Column(Boolean, nullable=False, server_default=text("false"))
    accept_risk = Column(Boolean, nullable=False, server_default=text("false"))
    recast_risk = Column(Boolean, nullable=False, server_default=text("false"))
    first_seen = Column(DateTime(timezone=True))
    last_seen = Column(DateTime(timezone=True))
    raw = _jsonb()
    synced_at = _now()
    __table_args__ = (
        UniqueConstraint("host_asset_id", "plugin_id", "port", "protocol"),
        Index("ix_vulnerabilities_plugin_id", "plugin_id"),
        Index("ix_vulnerabilities_severity", "severity"),
        Index("ix_vulnerabilities_vpr_score", "vpr_score"),
        Index("ix_vulnerabilities_cve", "cve", postgresql_using="gin"),
    )


class ScQueryFinding(Base):
    """Which queries currently return a finding. A finding with no row here is
    no longer returned by any query and is deleted by the connector."""
    __tablename__ = "sc_query_findings"
    query_id = Column(Integer, ForeignKey("sc_queries.id", ondelete="CASCADE"), primary_key=True)
    vulnerability_id = Column(BigInteger, ForeignKey("vulnerabilities.id", ondelete="CASCADE"), primary_key=True)
    __table_args__ = (Index("ix_sc_query_findings_vulnerability_id", "vulnerability_id"),)


# ---- Active Directory (from Tenable Identity Exposure) --------------------------
class AdObjectMixin:
    """Columns shared by every AD table. `attributes` holds every attribute TIE
    returned (lower-case names, decoded values); only key fields are columns.
    `object_key` is the lower-case SID, or the lower-case TIE objectId for
    objects without a SID. It matches the node keys of the TIE control graph."""
    id = Column(BigInteger, Identity(), primary_key=True)
    directory_id = Column(Integer, nullable=False)
    tie_id = Column(BigInteger, nullable=False)
    object_id = Column(String(128), nullable=False)
    object_key = Column(String(255), nullable=False)
    object_guid = Column(String(64))
    sid = Column(String(255))
    dn = Column(Text)
    sam_account_name = Column(String(255))
    name = Column(Text)
    when_created = Column(DateTime(timezone=True))
    when_changed = Column(DateTime(timezone=True))
    attributes = Column(JSONB, server_default=text("'{}'::jsonb"), nullable=False)
    synced_at = Column(DateTime(timezone=True), server_default=func.now(), nullable=False)

    @declared_attr
    def __table_args__(cls):
        t = cls.__tablename__
        return (
            UniqueConstraint("directory_id", "tie_id"),
            Index(f"ix_{t}_object_key", "object_key"),
            Index(f"ix_{t}_sam_account_name_lower", text("lower(sam_account_name)")),
        )


class AdUser(AdObjectMixin, Base):
    __tablename__ = "ad_users"
    account_type = Column(String(16), nullable=False, server_default="user")  # user | service | gmsa
    enabled = Column(Boolean)
    user_principal_name = Column(String(255))
    display_name = Column(Text)
    admin_count = Column(Integer)
    last_logon = Column(DateTime(timezone=True))
    pwd_last_set = Column(DateTime(timezone=True))


class AdGroup(AdObjectMixin, Base):
    __tablename__ = "ad_groups"
    group_type = Column(String(255))
    member_count = Column(Integer, nullable=False, server_default="0")
    description = Column(Text)


class AdComputer(AdObjectMixin, Base):
    __tablename__ = "ad_computers"
    enabled = Column(Boolean)
    dns_host_name = Column(String(255))
    os = Column(Text)
    os_version = Column(String(255))
    last_logon = Column(DateTime(timezone=True))


class AdGroupPolicy(AdObjectMixin, Base):
    __tablename__ = "ad_group_policies"
    display_name = Column(Text)
    gpc_file_sys_path = Column(Text)


class AdTierAssignment(Base):
    """Tier of an AD object. Rows with source `tie` are replaced on every TIE
    sync (Tier 0 from the control graph). Rows with source `manual` belong to
    the user, are never touched by a sync and take precedence. A manual row
    with a NULL tier removes the object from every tier."""
    __tablename__ = "ad_tier_assignments"
    id = Column(BigInteger, Identity(), primary_key=True)
    object_key = Column(String(255), nullable=False)
    source = Column(String(16), nullable=False)               # tie | manual
    tier = Column(SmallInteger)                               # 0 | 1 | 2 | NULL
    name = Column(Text)
    object_type = Column(String(64))
    detail = _jsonb()
    updated_at = _now()
    __table_args__ = (UniqueConstraint("object_key", "source"),)
