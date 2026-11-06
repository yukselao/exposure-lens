"""Pull cumulative vulnerabilities for the queries of a pool into PostgreSQL."""
from datetime import datetime, timezone

from sqlalchemy import delete, func, select, text
from sqlalchemy.dialects.postgresql import insert

from eldb.database import new_session
from eldb.models import HostAsset, ScPool, ScQuery, ScQueryFinding, Vulnerability
from elcommon import config, jobs
from elcommon.crypto import SecretError
from elcommon.jsonsafe import clean
from elcommon.worker import JobError

from filters import build_filters
from mapping import host_row, vulnerability_row
from sc_client import ScError, TenableSC

PAGE_SIZE = 1000
LINK_CHUNK = 5000


def make_client(session) -> TenableSC:
    cfg = config.get_section(session, "seccenter")
    url = (cfg.get("url") or "").strip()
    try:
        access_key = config.secret(cfg, "access_key")
        secret_key = config.secret(cfg, "secret_key")
    except SecretError as err:
        raise JobError(f"Security Center keys cannot be read: {err}") from err
    if not url or not access_key or not secret_key:
        raise JobError("Security Center is not configured. Enter the URL and API keys under "
                       "Settings > Security Center.")
    return TenableSC(url, access_key, secret_key, verify_ssl=bool(cfg.get("verify_ssl", True)),
                     timeout=int(cfg.get("timeout_seconds") or 120))


# ---- job: test_connection ----------------------------------------------------
def test_connection(job, log) -> dict:
    """Check the URL and keys, and cache the repository and plugin family
    lists for the query editor (settings section `seccenter_meta`)."""
    session = new_session()
    try:
        meta = {"ok": False, "tested_at": datetime.now(timezone.utc).isoformat()}
        try:
            client = make_client(session)
            user = client.current_user()
            meta.update(ok=True, username=user.get("username") or "",
                        role=(user.get("role") or {}).get("name") or "",
                        repositories=client.repositories(), families=client.plugin_families())
        except (ScError, JobError) as err:
            previous = config.get_section(session, "seccenter_meta")
            meta.update(error=str(err), repositories=previous.get("repositories", []),
                        families=previous.get("families", []))
            config.put_section(session, "seccenter_meta", meta)
            session.commit()
            raise JobError(str(err)) from err
        config.put_section(session, "seccenter_meta", meta)
        session.commit()
        return {"username": meta["username"], "role": meta["role"],
                "repositories": len(meta["repositories"]), "families": len(meta["families"])}
    finally:
        session.close()


# ---- job: sync ---------------------------------------------------------------
def sync_pool(job, log) -> dict:
    session = new_session()
    try:
        pool = session.get(ScPool, job["target_id"]) if job["target_id"] else None
        if pool is None:
            raise JobError("The pool of this job no longer exists.")
        queries = session.scalars(select(ScQuery).where(
            ScQuery.pool_id == pool.id, ScQuery.enabled.is_(True)).order_by(ScQuery.id)).all()
        if not queries:
            raise JobError(f"Pool '{pool.name}' has no enabled query.")
        client = make_client(session)

        stats = {"pool": pool.name, "queries": len(queries), "queries_failed": 0,
                 "findings_fetched": 0}
        errors = []
        for index, query in enumerate(queries, start=1):
            fields = {"job_id": job["id"], "pool": pool.name, "query_id": query.id, "query": query.name}
            jobs.progress(job["id"], query=query.name, query_index=index,
                          query_count=len(queries), fetched=0, total=None)
            try:
                result = _sync_query(session, client, query, job, log, fields)
                session.commit()
                stats["findings_fetched"] += result["fetched"]
                log.info("sc_query_synced", **fields, **result)
            except ScError as err:
                # Nothing of this query is written; the other queries still run.
                session.rollback()
                stats["queries_failed"] += 1
                errors.append(f"{query.name}: {err}")
                log.error("sc_query_failed", **fields, error=str(err))

        stats.update(_delete_unreferenced(session))
        if stats["queries_failed"] < len(queries):
            pool.last_sync_at = func.now()
        session.commit()
        stats["hosts_total"] = session.scalar(select(func.count()).select_from(HostAsset))
        stats["vulnerabilities_total"] = session.scalar(select(func.count()).select_from(Vulnerability))
        if errors:
            jobs.progress(job["id"], **stats)
            raise JobError(f"{len(errors)} of {len(queries)} queries failed. " + " | ".join(errors))
        return stats
    finally:
        session.close()


def _sync_query(session, client, query, job, log, fields) -> dict:
    """Fetch every page of one query and upsert it. All of it happens in the
    caller's transaction, so a failed fetch leaves the tables untouched."""
    filters = build_filters(query.filters)
    limit = max(0, query.max_records or 0)
    log.info("sc_query_started", **fields, filters=[f["filterName"] for f in filters], max_records=limit)

    def on_retry(attempt, delay, error):
        log.warning("sc_request_retry", **fields, attempt=attempt, delay_seconds=delay, error=error)

    finding_ids, hosts_seen = set(), set()
    fetched, total = 0, None
    while True:
        want = PAGE_SIZE if not limit else min(PAGE_SIZE, limit - fetched)
        if want <= 0:
            break
        response = client.cumulative_vulns(filters, fetched, fetched + want, on_retry=on_retry)
        if total is None:
            total = int(response.get("totalRecords") or 0)
        records = clean(response["results"][:want])
        if records:
            ids, host_ids = _upsert_page(session, records)
            finding_ids.update(ids)
            hosts_seen.update(host_ids)
        fetched += len(records)
        jobs.progress(job["id"], fetched=fetched, total=total)
        log.info("sc_page_fetched", **fields, fetched=fetched, total=total)
        if len(records) < want or fetched >= total:
            break

    expected = min(total, limit) if limit else total
    if fetched < expected:
        raise ScError(f"Security Center returned {fetched} of {expected} records; "
                      "the result changed during the fetch. Nothing was written, run the sync again.")

    # This query now returns exactly `finding_ids`.
    session.execute(delete(ScQueryFinding).where(ScQueryFinding.query_id == query.id))
    ordered = sorted(finding_ids)
    for i in range(0, len(ordered), LINK_CHUNK):
        session.execute(insert(ScQueryFinding), [
            {"query_id": query.id, "vulnerability_id": vid} for vid in ordered[i:i + LINK_CHUNK]])
    query.last_sync_at = func.now()
    query.last_total = total
    query.last_fetched = len(finding_ids)
    return {"fetched": fetched, "total_in_sc": total, "findings": len(finding_ids),
            "hosts": len(hosts_seen), "truncated": bool(limit and total > fetched)}


def _upsert_page(session, records):
    """Upsert the hosts and findings of one page. Returns (finding ids, host ids)."""
    hosts = {}
    for record in records:
        row = host_row(record)
        key = (row["repository_id"], row["host_key"])
        seen = hosts.get(key)
        if seen:  # keep the widest first/last seen window of the page
            row["first_seen"] = _least(seen["first_seen"], row["first_seen"])
            row["last_seen"] = _greatest(seen["last_seen"], row["last_seen"])
        hosts[key] = row

    stmt = insert(HostAsset).values(list(hosts.values()))
    update = {c: stmt.excluded[c] for c in (
        "repository_name", "host_uuid", "agent_uuid", "ip", "dns_name", "netbios_name",
        "mac_address", "os", "acr_score", "aes_score", "raw")}
    update["first_seen"] = func.least(HostAsset.first_seen, stmt.excluded.first_seen)
    update["last_seen"] = func.greatest(HostAsset.last_seen, stmt.excluded.last_seen)
    update["synced_at"] = func.now()
    host_ids = {
        (r.repository_id, r.host_key): r.id
        for r in session.execute(stmt.on_conflict_do_update(
            index_elements=["repository_id", "host_key"], set_=update,
        ).returning(HostAsset.id, HostAsset.repository_id, HostAsset.host_key))
    }

    findings = {}
    for record in records:
        host = host_row(record)
        row = vulnerability_row(record)
        row["host_asset_id"] = host_ids[(host["repository_id"], host["host_key"])]
        findings[(row["host_asset_id"], row["plugin_id"], row["port"], row["protocol"])] = row

    stmt = insert(Vulnerability).values(list(findings.values()))
    update = {c: stmt.excluded[c] for c in (
        "vuln_uuid", "plugin_name", "family_id", "family_name", "severity", "vpr_score", "cvss_v3",
        "cvss_v2", "epss_score", "cve", "exploit_available", "accept_risk", "recast_risk",
        "first_seen", "last_seen", "raw")}
    update["synced_at"] = func.now()
    ids = session.scalars(stmt.on_conflict_do_update(
        index_elements=["host_asset_id", "plugin_id", "port", "protocol"], set_=update,
    ).returning(Vulnerability.id)).all()
    return ids, set(host_ids.values())


def _delete_unreferenced(session) -> dict:
    """Hard delete: findings no query returns any more, then hosts left without findings."""
    findings = session.execute(text("""
        DELETE FROM vulnerabilities v
        WHERE NOT EXISTS (SELECT 1 FROM sc_query_findings f WHERE f.vulnerability_id = v.id)""")).rowcount
    hosts = session.execute(text("""
        DELETE FROM host_assets h
        WHERE NOT EXISTS (SELECT 1 FROM vulnerabilities v WHERE v.host_asset_id = h.id)""")).rowcount
    return {"findings_deleted": findings, "hosts_deleted": hosts}


def _least(a, b):
    return b if a is None else a if b is None else min(a, b)


def _greatest(a, b):
    return b if a is None else a if b is None else max(a, b)


# ---- scheduler -----------------------------------------------------------------
def enqueue_due_pools(log) -> None:
    """Queue a sync for every enabled pool whose interval has elapsed."""
    session = new_session()
    try:
        pools = session.execute(select(ScPool.id, ScPool.name, ScPool.sync_interval_minutes).where(
            ScPool.enabled.is_(True), ScPool.sync_interval_minutes > 0)).all()
    finally:
        session.close()
    for pool_id, name, interval in pools:
        elapsed = jobs.minutes_since_last("sc", "sync", pool_id)
        if elapsed is not None and elapsed < interval:
            continue
        if jobs.enqueue_if_idle("sc", "sync", pool_id, "schedule"):
            log.info("sc_sync_scheduled", pool_id=pool_id, pool=name, interval_minutes=interval)
