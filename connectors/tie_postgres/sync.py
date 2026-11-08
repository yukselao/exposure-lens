"""Mirror AD objects from the TIE connector into PostgreSQL.

What exists in TIE is upserted; what no longer exists in TIE is deleted."""
import os
from datetime import datetime, timezone

from sqlalchemy import delete, func, select, text as sql
from sqlalchemy.dialects.postgresql import insert

from eldb.database import new_session
from eldb.models import AdComputer, AdDomain, AdGroup, AdGroupPolicy, AdTierAssignment, AdUser
from elcommon import config, jobs
from elcommon.crypto import SecretError
from elcommon.worker import JobError

from decode import decode_attributes, text as text_value
from mapping import to_row
from tie_client import TieClient, TieError

MODELS = {"ad_users": AdUser, "ad_groups": AdGroup, "ad_computers": AdComputer,
          "ad_group_policies": AdGroupPolicy}

# Expressions from connectors/tie/README.md ("Querying AD objects"). Managed
# service accounts match the computer expression; mapping.table_for() files
# them under ad_users.
CLASS_QUERIES = (
    ("users", "objectClass=user AND NOT objectClass=computer"),
    ("computers", "objectClass=computer"),
    ("groups", "objectClass=group"),
    ("group_policies", "objectClass=groupPolicyContainer"),
)

UPSERT_CHUNK = 500
TIER0_MAX_NODES = 1_000_000


def make_client(session) -> TieClient:
    cfg = config.get_section(session, "tie")
    url = (cfg.get("connector_url") or os.environ.get("TIE_CONNECTOR_URL") or "").strip()
    try:
        token = config.secret(cfg, "token")
    except SecretError as err:
        raise JobError(f"The TIE connector token cannot be read: {err}") from err
    if not token:  # fall back to the Docker secret shared with connectors/tie
        try:
            with open(os.environ.get("TIE_CONNECTOR_TOKEN_FILE", ""), encoding="utf-8") as handle:
                token = handle.read().strip()
        except OSError:
            token = ""
    if not url or not token:
        raise JobError("The TIE connector is not configured. Enter its URL and token under "
                       "Settings > Identity Exposure.")
    return TieClient(url, token, timeout=int(cfg.get("timeout_seconds") or 600))


def _settings(session) -> dict:
    return config.get_section(session, "tie")


# ---- job: test_connection ----------------------------------------------------
def test_connection(job, log) -> dict:
    session = new_session()
    try:
        meta = {"ok": False, "tested_at": datetime.now(timezone.utc).isoformat()}
        try:
            status = make_client(session).status()
            meta.update(ok=True, state=status.get("state"), object_count=status.get("objectCount"))
        except (TieError, JobError) as err:
            meta["error"] = str(err)
            config.put_section(session, "tie_meta", meta)
            session.commit()
            raise JobError(str(err)) from err
        config.put_section(session, "tie_meta", meta)
        session.commit()
        return {"state": meta["state"], "object_count": meta["object_count"]}
    finally:
        session.close()


# ---- job: sync ---------------------------------------------------------------
def sync_ad(job, log) -> dict:
    session = new_session()
    fields = {"job_id": job["id"]}
    try:
        client = make_client(session)
        try:
            status = client.wait_until_ready(
                max_wait_seconds=int(_settings(session).get("ready_wait_seconds") or 900),
                on_wait=lambda s: (jobs.progress(job["id"], step="waiting_for_tie", tie_state=s.get("state"),
                                                 tie_objects_fetched=s.get("objectsFetched")),
                                   log.info("tie_waiting_for_snapshot", **fields, state=s.get("state"),
                                            objects_fetched=s.get("objectsFetched"))))
            log.info("tie_snapshot_ready", **fields, object_count=status.get("objectCount"))

            # now() is the transaction start time and stays constant for the whole
            # sync, so every row written by this run gets exactly this timestamp.
            started = session.scalar(select(func.now()))
            seen = {name: set() for name in MODELS}
            complete = True
            stats = {"snapshot_objects": status.get("objectCount")}
            for label, expression in CLASS_QUERIES:
                jobs.progress(job["id"], step=f"fetching_{label}")
                result = client.tool("query_ad_objects", {"expression": expression, "limit": 0})
                objects = result.get("objects") or []
                total = int(result.get("total") or 0)
                if result.get("truncated") or len(objects) != total:
                    complete = False
                    log.warning("tie_class_incomplete", **fields, object_class=label,
                                returned=len(objects), total=total)
                written = _upsert(session, objects, seen)
                stats[f"{label}_fetched"] = len(objects)
                log.info("tie_class_synced", **fields, object_class=label, fetched=len(objects),
                         total=total, written=written)

            stats.update(_delete_missing(session, started, seen, complete, log, fields))
            jobs.progress(job["id"], step="tier0")
            stats.update(_sync_tier0(session, client, log, fields))
            jobs.progress(job["id"], step="domains")
            stats.update(_sync_domains(session, client, log, fields))
            stats.update(_sync_group_members(session, log, fields))
        except TieError as err:
            session.rollback()
            raise JobError(str(err)) from err
        session.commit()
        for name, model in MODELS.items():
            stats[name] = session.scalar(select(func.count()).select_from(model))
        if jobs.enqueue_if_idle("ctem", "correlate", None, "chain"):
            log.info("ctem_correlate_queued", **fields)
        return stats
    finally:
        session.close()


def _upsert(session, objects, seen) -> int:
    rows = {name: {} for name in MODELS}
    for obj in objects:
        table, row = to_row(obj)
        if table:
            rows[table][(row["directory_id"], row["tie_id"])] = row
    written = 0
    for table, by_key in rows.items():
        model = MODELS[table]
        values = list(by_key.values())
        seen[table].update(by_key)
        for i in range(0, len(values), UPSERT_CHUNK):
            stmt = insert(model).values(values[i:i + UPSERT_CHUNK])
            update = {c: stmt.excluded[c] for c in values[0] if c not in ("directory_id", "tie_id")}
            update["synced_at"] = func.now()
            session.execute(stmt.on_conflict_do_update(
                index_elements=["directory_id", "tie_id"], set_=update))
        written += len(values)
    return written


def _delete_missing(session, started, seen, complete, log, fields) -> dict:
    """Hard delete rows TIE no longer has. Skipped when the pull was partial or
    returned nothing at all, so a bad snapshot cannot empty the tables."""
    if not complete or not any(seen.values()):
        log.warning("tie_delete_skipped", **fields,
                    reason="incomplete pull" if not complete else "TIE returned no objects")
        return {"deleted": 0, "delete_skipped": True}
    deleted = {}
    for table, model in MODELS.items():
        # Rows written by this run carry synced_at == started; older rows are
        # the ones TIE no longer has.
        deleted[table] = session.execute(delete(model).where(model.synced_at < started)).rowcount
    if any(deleted.values()):
        log.info("tie_objects_deleted", **fields, **deleted)
    return {"deleted": sum(deleted.values()), "delete_skipped": False}


def _sync_tier0(session, client, log, fields) -> dict:
    """Replace the `tie` tier assignments with the control graph's Tier 0
    (built-in privileged groups plus every principal that can reach them).
    Manual assignments are left alone."""
    result = client.tool("get_tier0", {"maxNodes": TIER0_MAX_NODES})
    if result.get("truncated"):
        log.warning("tie_tier0_truncated", **fields, truncated=result.get("truncated"))
    rows = {}
    for entry in result.get("builtin") or []:
        node = entry.get("node") or {}
        if node.get("key"):
            rows[node["key"].lower()] = {"name": node.get("name"), "object_type": node.get("type"),
                                         "detail": {"origin": "builtin"}}
    for entry in result.get("derived") or []:
        node = entry.get("node") or {}
        key = (node.get("key") or "").lower()
        if key and key not in rows:
            path = entry.get("escalationPath") or []
            rows[key] = {"name": node.get("name"), "object_type": node.get("type"),
                         "detail": {"origin": "derived", "hops": entry.get("hops"),
                                    "path": [{"from": (s.get("from") or {}).get("name"),
                                              "to": (s.get("to") or {}).get("name"),
                                              "kind": s.get("kind")} for s in path[:10]]}}
    if not rows:
        log.warning("tie_tier0_empty", **fields, note=result.get("note"))
        return {"tier0": 0}

    session.execute(delete(AdTierAssignment).where(AdTierAssignment.source == "tie"))
    values = [{"object_key": key, "source": "tie", "tier": 0, **row} for key, row in rows.items()]
    for i in range(0, len(values), UPSERT_CHUNK):
        session.execute(insert(AdTierAssignment), values[i:i + UPSERT_CHUNK])
    log.info("tie_tier0_synced", **fields, tier0=len(values),
             builtin=result.get("builtinCount"), derived=result.get("derivedCount"))
    return {"tier0": len(values)}


def _sync_domains(session, client, log, fields) -> dict:
    """NetBIOS and DNS names of each domain, from the crossRef objects. Plugin
    output names domains by NetBIOS name, so account matching needs this map."""
    result = client.tool("query_ad_objects", {"expression": "objectClass=crossRef", "limit": 0})
    rows = {}
    for obj in result.get("objects") or []:
        attributes = decode_attributes(obj.get("attributes"))
        netbios = text_value(attributes.get("netbiosname"))
        if netbios:
            directory = int(obj.get("directoryId") or 0)
            rows[(directory, netbios)] = {"directory_id": directory, "netbios_name": netbios,
                                          "dns_name": text_value(attributes.get("dnsroot")),
                                          "nc_name": text_value(attributes.get("ncname"))}
    if not rows:
        log.warning("tie_domains_empty", **fields)
        return {"domains": 0}
    session.execute(delete(AdDomain))
    session.execute(insert(AdDomain), list(rows.values()))
    log.info("tie_domains_synced", **fields, domains=len(rows))
    return {"domains": len(rows)}


def _sync_group_members(session, log, fields) -> dict:
    """Direct membership: match each `member` DN to a synced object."""
    session.execute(sql("DELETE FROM ad_group_members"))
    count = session.execute(sql("""
        INSERT INTO ad_group_members (group_key, member_key)
        SELECT DISTINCT g.object_key, o.object_key
        FROM ad_groups g
        CROSS JOIN LATERAL jsonb_array_elements_text(
            CASE WHEN jsonb_typeof(g.attributes->'member') = 'array' THEN g.attributes->'member'
                 WHEN jsonb_typeof(g.attributes->'member') = 'string' THEN jsonb_build_array(g.attributes->'member')
                 ELSE '[]'::jsonb END) AS m(dn)
        JOIN v_ad_objects o ON lower(o.dn) = lower(m.dn) AND o.directory_id = g.directory_id
        ON CONFLICT DO NOTHING""")).rowcount
    log.info("tie_group_members_synced", **fields, members=count)
    return {"group_members": count}


# ---- scheduler -----------------------------------------------------------------
def enqueue_if_due(log) -> None:
    session = new_session()
    try:
        interval = int(_settings(session).get("sync_interval_minutes") or 0)
    finally:
        session.close()
    if interval <= 0:
        return
    elapsed = jobs.minutes_since_last("tie_postgres", "sync", None)
    if elapsed is not None and elapsed < interval:
        return
    if jobs.enqueue_if_idle("tie_postgres", "sync", None, "schedule"):
        log.info("tie_sync_scheduled", interval_minutes=interval)
