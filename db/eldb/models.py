"""Schema for Exposure Lens. This file is the single source of truth; Alembic
revisions under db/alembic/versions are generated from it."""
from sqlalchemy import (
    BigInteger, Boolean, Column, DateTime, Float, ForeignKey, Identity, Index,
    Integer, LargeBinary, SmallInteger, String, Text, UniqueConstraint, func, text,
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


class AiPack(Base):
    """A knowledge pack uploaded for Agent Tenable: a zip with agents/, skills/,
    tools/ and prompts/ folders, unpacked under data/ai-packs/<id>. Only active
    packs are loaded into the chat."""
    __tablename__ = "ai_packs"
    id = Column(BigInteger, Identity(), primary_key=True)
    name = Column(String(200), nullable=False)
    version = Column(String(64))
    description = Column(Text)
    file_name = Column(String(255), nullable=False)
    sha256 = Column(String(64), nullable=False, unique=True)
    size_bytes = Column(BigInteger, nullable=False)
    manifest = _jsonb()
    active = Column(Boolean, nullable=False, server_default=text("false"))
    uploaded_at = _now()


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
        Index("ix_vulnerabilities_top_vpr", "plugin_id", "host_asset_id", postgresql_include=["vpr_score", "severity", "exploit_available"],
              postgresql_where=text("vpr_score >= 9")),
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


class AdDomain(Base):
    """A domain known to a TIE directory, from its crossRef objects."""
    __tablename__ = "ad_domains"
    id = Column(Integer, Identity(), primary_key=True)
    directory_id = Column(Integer, nullable=False)
    netbios_name = Column(String(255), nullable=False)
    dns_name = Column(String(255))
    nc_name = Column(Text)
    __table_args__ = (
        UniqueConstraint("directory_id", "netbios_name"),
        Index("ix_ad_domains_netbios_name_lower", text("lower(netbios_name)")),
        Index("ix_ad_domains_dns_name_lower", text("lower(dns_name)")),
    )


class AdGroupMember(Base):
    """Direct AD group membership, resolved from the `member` attribute."""
    __tablename__ = "ad_group_members"
    group_key = Column(String(255), primary_key=True)
    member_key = Column(String(255), primary_key=True)
    __table_args__ = (Index("ix_ad_group_members_member_key", "member_key"),)


# ---- CTEM: plugin data -------------------------------------------------------
class PluginCatalog(Base):
    """Every local informational plugin. Only rows that are `selected`, enabled
    and have an extractor are collected."""
    __tablename__ = "plugin_catalog"
    plugin_id = Column(Integer, primary_key=True, autoincrement=False)
    name = Column(Text, nullable=False)
    family = Column(String(255), nullable=False, server_default="")
    agent = Column(Boolean, nullable=False, server_default=text("false"))
    source_file = Column(String(255), nullable=False, server_default="")
    dependencies = Column(Text, nullable=False, server_default="")
    plugin_class = Column(String(16), nullable=False, server_default="inventory")  # product | inventory
    handbook_category = Column(String(16))
    scenario = Column(String(8))
    extractor = Column(String(32))
    has_attachments = Column(Boolean, nullable=False, server_default=text("false"))
    review_status = Column(String(16), nullable=False, server_default="candidate")  # candidate | selected | ignored
    enabled = Column(Boolean, nullable=False, server_default=text("true"))
    sc_finding_count = Column(Integer)
    last_collected_at = Column(DateTime(timezone=True))
    __table_args__ = (Index("ix_plugin_catalog_review_status", "review_status"),
                      Index("ix_plugin_catalog_scenario", "scenario"))


class PluginOutput(Base):
    """Raw output of one plugin on one host. A row is parsed again only when
    its hash, its attachments or its extractor version changed."""
    __tablename__ = "plugin_outputs"
    id = Column(BigInteger, Identity(), primary_key=True)
    host_asset_id = Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"), nullable=False)
    plugin_id = Column(Integer, nullable=False)
    port = Column(Integer, nullable=False, server_default="0")
    protocol = Column(String(16), nullable=False, server_default="")
    output = Column(Text, nullable=False, server_default="")
    output_hash = Column(String(64), nullable=False)
    truncated = Column(Boolean, nullable=False, server_default=text("false"))
    refers_to_attachment = Column(Boolean, nullable=False, server_default=text("false"))
    scan_result_id = Column(Integer)
    first_seen = Column(DateTime(timezone=True))
    last_seen = Column(DateTime(timezone=True))
    extracted_hash = Column(String(64))
    extractor_version = Column(Integer)
    extract_status = Column(String(16), nullable=False, server_default="pending")  # pending | ok | failed | skipped
    extract_error = Column(Text)
    records = Column(Integer)
    collected_at = _now()
    __table_args__ = (
        UniqueConstraint("host_asset_id", "plugin_id", "port", "protocol"),
        Index("ix_plugin_outputs_plugin_id", "plugin_id"),
        Index("ix_plugin_outputs_pending", "plugin_id", postgresql_where=text("extract_status = 'pending'")),
        Index("ix_plugin_outputs_failed", "plugin_id", postgresql_where=text("extract_status = 'failed'")),
    )


class PluginAttachment(Base):
    """A file a plugin attached to its finding, taken from a scan result."""
    __tablename__ = "plugin_attachments"
    id = Column(BigInteger, Identity(), primary_key=True)
    plugin_output_id = Column(BigInteger, ForeignKey("plugin_outputs.id", ondelete="CASCADE"), nullable=False)
    scan_result_id = Column(Integer, nullable=False)
    sc_attachment_id = Column(String(64), nullable=False)
    name = Column(Text, nullable=False, server_default="")
    mime_type = Column(String(128), nullable=False, server_default="")
    size = Column(BigInteger, nullable=False, server_default="0")
    sha256 = Column(String(64))
    content = Column(LargeBinary)
    parse_status = Column(String(16), nullable=False, server_default="stored")  # stored | too_large | parsed | failed
    fetched_at = _now()
    __table_args__ = (UniqueConstraint("plugin_output_id", "name"), Index("ix_plugin_attachments_sha256", "sha256"))


class Principal(Base):
    """Every identity seen in plugin output. `natural_key` makes spellings of
    one account (LABNET\\x, labnet.local\\x) a single row. AD matching happens
    only here, in `ad_object_key`."""
    __tablename__ = "principals"
    id = Column(BigInteger, Identity(), primary_key=True)
    natural_key = Column(String(600), nullable=False, unique=True)
    kind = Column(String(16), nullable=False, server_default="unknown")   # user | group | computer | unknown
    scope = Column(String(16), nullable=False, server_default="unknown")  # domain | local | builtin | unknown
    domain = Column(String(255))
    name = Column(String(255), nullable=False)
    sid = Column(String(255))
    host_asset_id = Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"))
    ad_object_key = Column(String(255))
    resolved_by = Column(String(16))     # sid | domain_name | upn | host_domain | ambiguous
    privileged_hint = Column(Boolean, nullable=False, server_default=text("false"))
    __table_args__ = (
        Index("ix_principals_sid_lower", text("lower(sid)")),
        Index("ix_principals_domain_name_lower", text("lower(domain)"), text("lower(name)")),
        Index("ix_principals_ad_object_key", "ad_object_key"),
    )


class _HostFact:
    """Columns of every table written by an extractor. Rows belong to the plugin
    output they came from; `source_output_id` is part of each unique key."""
    id = Column(BigInteger, Identity(), primary_key=True)
    plugin_id = Column(Integer, nullable=False)
    first_seen = Column(DateTime(timezone=True), server_default=func.now(), nullable=False)
    last_seen = Column(DateTime(timezone=True), server_default=func.now(), nullable=False)

    @declared_attr
    def host_asset_id(cls):
        return Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"), nullable=False, index=True)

    @declared_attr
    def source_output_id(cls):
        return Column(BigInteger, ForeignKey("plugin_outputs.id", ondelete="CASCADE"), nullable=False)


def _principal_fk():
    return Column(BigInteger, ForeignKey("principals.id", ondelete="CASCADE"), index=True)


class HostIoc(_HostFact, Base):
    __tablename__ = "host_iocs"
    ioc_type = Column(String(32), nullable=False)
    value = Column(Text, nullable=False)
    value_norm = Column(Text, nullable=False)
    principal_id = _principal_fk()
    attributes = _jsonb()
    __table_args__ = (UniqueConstraint("source_output_id", "ioc_type", "value_norm"),
                      Index("ix_host_iocs_type_value", "ioc_type", "value_norm"))


class Software(Base):
    __tablename__ = "software"
    id = Column(BigInteger, Identity(), primary_key=True)
    name = Column(Text, nullable=False)
    name_norm = Column(Text, nullable=False)
    version = Column(String(255), nullable=False, server_default="")
    __table_args__ = (UniqueConstraint("name_norm", "version"), Index("ix_software_name_norm", "name_norm"))


class HostSoftware(_HostFact, Base):
    __tablename__ = "host_software"
    software_id = Column(BigInteger, ForeignKey("software.id", ondelete="CASCADE"), nullable=False, index=True)
    installed_at = Column(DateTime(timezone=True))
    source_type = Column(String(16), nullable=False, server_default="")   # windows | deb | rpm | mac | other
    __table_args__ = (UniqueConstraint("source_output_id", "software_id"),)


class HostGroupMember(_HostFact, Base):
    __tablename__ = "host_group_members"
    group_name = Column(String(255), nullable=False)
    group_sid = Column(String(255))
    principal_id = _principal_fk()
    __table_args__ = (UniqueConstraint("source_output_id", "group_name", "principal_id"),
                      Index("ix_host_group_members_group_sid", "group_sid"))


class HostLocalAccount(_HostFact, Base):
    __tablename__ = "host_local_accounts"
    principal_id = _principal_fk()
    rid = Column(Integer)
    account_type = Column(String(16), nullable=False, server_default="user")  # user | system
    disabled = Column(Boolean)
    flags = _jsonb()
    groups = Column(ARRAY(Text), nullable=False, server_default=text("'{}'::text[]"))
    shell = Column(String(255))
    __table_args__ = (UniqueConstraint("source_output_id", "principal_id"),)


class HostSession(_HostFact, Base):
    __tablename__ = "host_sessions"
    principal_id = _principal_fk()
    session_type = Column(String(16), nullable=False)  # session | last_logon | smb_session
    __table_args__ = (UniqueConstraint("source_output_id", "principal_id", "session_type"),)


class HostService(_HostFact, Base):
    __tablename__ = "host_services"
    service_name = Column(String(255), nullable=False)
    display_name = Column(Text)
    path = Column(Text)
    start_type = Column(String(32))
    principal_id = _principal_fk()
    __table_args__ = (UniqueConstraint("source_output_id", "service_name"),)


class HostScanCoverage(_HostFact, Base):
    __tablename__ = "host_scan_coverage"
    status = Column(String(24), nullable=False)   # credentialed | no_credentials | failed | insufficient_privilege
    protocol = Column(String(16), nullable=False, server_default="")
    principal_id = _principal_fk()
    scan_name = Column(Text)
    scan_time = Column(DateTime(timezone=True))
    detail = _jsonb()
    __table_args__ = (UniqueConstraint("source_output_id", "status", "protocol"),)


class HostIdentity(_HostFact, Base):
    __tablename__ = "host_identities"
    hostname = Column(String(255))
    fqdn = Column(String(255))
    netbios_name = Column(String(255))
    domain = Column(String(255))
    workgroup = Column(String(255))
    sam_account_name = Column(String(255))
    domain_role = Column(String(64))
    dn = Column(Text)
    __table_args__ = (UniqueConstraint("source_output_id"),
                      Index("ix_host_identities_sam_lower", text("lower(sam_account_name)")),
                      Index("ix_host_identities_fqdn_lower", text("lower(fqdn)")))


class BrowserExtension(Base):
    __tablename__ = "browser_extensions"
    id = Column(BigInteger, Identity(), primary_key=True)
    browser = Column(String(64), nullable=False)
    extension_id = Column(String(255), nullable=False)
    name = Column(Text, nullable=False, server_default="")
    description = Column(Text)
    __table_args__ = (UniqueConstraint("browser", "extension_id"), Index("ix_browser_extensions_extension_id", "extension_id"))


class HostBrowserExtension(_HostFact, Base):
    __tablename__ = "host_browser_extensions"
    extension_ref = Column(BigInteger, ForeignKey("browser_extensions.id", ondelete="CASCADE"), nullable=False, index=True)
    principal_id = _principal_fk()
    profile_user = Column(String(255))
    version = Column(String(128))
    updated_at = Column(DateTime(timezone=True))
    path = Column(Text)
    __table_args__ = (UniqueConstraint("source_output_id", "extension_ref", "profile_user", "path"),)


# ---- CTEM: correlation ---------------------------------------------------------
class IdentityHostAccess(Base):
    """Who touches which host, and on what evidence. Rebuilt by ctem_correlate.
    `identity_key` is the AD object key when the identity matched AD, otherwise
    the principal's natural key."""
    __tablename__ = "identity_host_access"
    id = Column(BigInteger, Identity(), primary_key=True)
    identity_key = Column(String(600), nullable=False)
    identity_name = Column(String(600), nullable=False)
    identity_kind = Column(String(16), nullable=False, server_default="unknown")
    scope = Column(String(16), nullable=False, server_default="unknown")
    principal_id = Column(BigInteger, ForeignKey("principals.id", ondelete="CASCADE"))
    ad_object_key = Column(String(255))
    privileged_hint = Column(Boolean, nullable=False, server_default=text("false"))
    host_asset_id = Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"), nullable=False)
    access_type = Column(String(24), nullable=False)
    access_class = Column(String(8), nullable=False)   # usage | right | scan
    via_group_key = Column(String(255))
    evidence_plugin_id = Column(Integer, nullable=False)
    evidence = Column(Text)
    confidence = Column(String(8), nullable=False, server_default="high")
    last_seen = Column(DateTime(timezone=True))
    __table_args__ = (
        UniqueConstraint("identity_key", "host_asset_id", "access_type"),
        Index("ix_identity_host_access_host_asset_id", "host_asset_id"),
        Index("ix_identity_host_access_ad_object_key", "ad_object_key"),
        Index("ix_identity_host_access_access_type", "access_type"),
    )


class AttackEdge(Base):
    __tablename__ = "attack_edges"
    id = Column(BigInteger, Identity(), primary_key=True)
    edge_type = Column(String(32), nullable=False)
    source_key = Column(String(600), nullable=False)
    target_key = Column(String(600), nullable=False)
    host_asset_id = Column(BigInteger, ForeignKey("host_assets.id", ondelete="CASCADE"))
    evidence_plugin_id = Column(Integer)
    confidence = Column(String(8), nullable=False, server_default="high")
    detail = _jsonb()
    last_seen = Column(DateTime(timezone=True))
    __table_args__ = (UniqueConstraint("edge_type", "source_key", "target_key"),
                      Index("ix_attack_edges_source_key", "source_key"),
                      Index("ix_attack_edges_target_key", "target_key"))
