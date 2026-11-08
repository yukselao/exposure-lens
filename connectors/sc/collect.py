"""plugin_collect: pull the output of the selected plugins into plugin_outputs,
with their attachments where a scan result still holds them."""
import hashlib
import json
import re
from datetime import datetime, timezone

from sqlalchemy import case, delete, func, select, text
from sqlalchemy.dialects.postgresql import insert

from eldb.database import new_session
from eldb.models import PluginAttachment, PluginCatalog, PluginOutput
from elcommon import config, jobs
from elcommon.jsonsafe import clean
from elcommon.worker import JobError

from filters import build_filters
from mapping import host_row, vulnerability_row
from sc_client import ScError
from sync import delete_unreferenced, make_client, upsert_hosts

PAGE_SIZE = 200          # plugin output can run to hundreds of KB per row
DEFAULT_INTERVAL = 360   # minutes
DEFAULT_MAX_ATTACHMENT_MB = 20
DEFAULT_SCAN_RESULTS = 10
TRUNCATED = "OUTPUT TRUNCATED"
ATTACHED = re.compile(r"\battached\b|see the attachment", re.I)


def split_output(plugin_text: str) -> str:
    return re.sub(r"^\s*<plugin_output>|</plugin_output>\s*$", "", plugin_text or "").strip("\n")


def output_row(record: dict, host_id: int, stamp) -> dict:
    finding = vulnerability_row(record)
    output = split_output(record.get("pluginText"))
    return {
        "host_asset_id": host_id, "plugin_id": finding["plugin_id"], "port": finding["port"],
        "protocol": finding["protocol"], "output": output,
        "output_hash": hashlib.sha256(output.encode("utf-8", "replace")).hexdigest(),
        "truncated": TRUNCATED in output, "refers_to_attachment": bool(ATTACHED.search(output[-400:])),
        "first_seen": finding["first_seen"], "last_seen": finding["last_seen"], "collected_at": stamp,
    }


def _upsert_outputs(session, rows):
    stmt = insert(PluginOutput).values(rows)
    changed = PluginOutput.output_hash != stmt.excluded.output_hash
    session.execute(stmt.on_conflict_do_update(
        index_elements=["host_asset_id", "plugin_id", "port", "protocol"],
        set_={
            "output": case((changed, stmt.excluded.output), else_=PluginOutput.output),
            "output_hash": case((changed, stmt.excluded.output_hash), else_=PluginOutput.output_hash),
            "truncated": case((changed, stmt.excluded.truncated), else_=PluginOutput.truncated),
            "refers_to_attachment": stmt.excluded.refers_to_attachment,
            "extract_status": case((changed, "pending"), else_=PluginOutput.extract_status),
            "last_seen": stmt.excluded.last_seen,
            "collected_at": stmt.excluded.collected_at,
        }))


def _collect_plugin(session, client, plugin, job, log) -> dict:
    fields = {"job_id": job["id"], "plugin_id": plugin.plugin_id}
    filters = build_filters({"plugin_ids": str(plugin.plugin_id)})
    stamp = datetime.now(timezone.utc)   # rows of this run carry exactly this value
    fetched, total = 0, None
    while True:
        response = client.cumulative_vulns(filters, fetched, fetched + PAGE_SIZE)
        if total is None:
            total = int(response.get("totalRecords") or 0)
        records = clean(response["results"])
        if records:
            hosts = upsert_hosts(session, records)
            rows = {}
            for record in records:
                host = host_row(record)
                row = output_row(record, hosts[(host["repository_id"], host["host_key"])], stamp)
                rows[(row["host_asset_id"], row["plugin_id"], row["port"], row["protocol"])] = row
            _upsert_outputs(session, list(rows.values()))
        fetched += len(records)
        log.info("plugin_collect_page", **fields, fetched=fetched, total=total)
        if len(records) < PAGE_SIZE or fetched >= total:
            break
    if fetched < total:
        raise ScError(f"Plugin {plugin.plugin_id}: Security Center returned {fetched} of {total} records.")
    removed = session.execute(delete(PluginOutput).where(
        PluginOutput.plugin_id == plugin.plugin_id, PluginOutput.collected_at < stamp)).rowcount
    plugin.sc_finding_count = total
    plugin.last_collected_at = func.now()
    return {"fetched": fetched, "removed": removed}


def plugin_collect(job, log) -> dict:
    session = new_session()
    try:
        plugins = session.scalars(select(PluginCatalog).where(
            PluginCatalog.review_status == "selected", PluginCatalog.enabled.is_(True),
            PluginCatalog.extractor.isnot(None)).order_by(PluginCatalog.plugin_id)).all()
        if not plugins:
            raise JobError("No plugin is selected. Select plugins under Settings > CTEM.")
        client = make_client(session)
        stats = {"plugins": len(plugins), "plugins_failed": 0, "outputs": 0, "outputs_removed": 0}
        errors = []
        for index, plugin in enumerate(plugins, start=1):
            jobs.progress(job["id"], plugin_id=plugin.plugin_id, plugin_index=index, plugin_count=len(plugins))
            try:
                result = _collect_plugin(session, client, plugin, job, log)
                session.commit()
                stats["outputs"] += result["fetched"]
                stats["outputs_removed"] += result["removed"]
            except ScError as err:
                session.rollback()
                stats["plugins_failed"] += 1
                errors.append(str(err))
                log.error("plugin_collect_failed", job_id=job["id"], plugin_id=plugin.plugin_id, error=str(err))
        stats.update(_collect_attachments(session, client, job, log))
        stats.update(delete_unreferenced(session))
        session.commit()
        jobs.enqueue_if_idle("ctem", "extract", None, "chain")
        if errors and stats["plugins_failed"] == len(plugins):
            raise JobError(f"All {len(plugins)} plugins failed. First error: {errors[0]}")
        return stats
    finally:
        session.close()


# ---- attachments -----------------------------------------------------------------
def attachment_refs(value) -> list:
    """Attachment references from the `attachment` field of an analysis row."""
    if not value:
        return []
    if isinstance(value, str):
        try:
            value = json.loads(value)
        except ValueError:
            return [{"id": part.strip(), "name": part.strip()} for part in value.split(",") if part.strip()]
    if isinstance(value, dict):
        value = [value]
    refs = []
    for item in value if isinstance(value, list) else [value]:
        if isinstance(item, dict) and item.get("id") is not None:
            refs.append({"id": str(item["id"]), "name": str(item.get("name") or item["id"]),
                         "type": str(item.get("type") or "")})
        elif isinstance(item, (str, int)):
            refs.append({"id": str(item), "name": str(item)})
    return refs


def _collect_attachments(session, client, job, log) -> dict:
    """For outputs that are cut off or point to a file, look in the recent scan
    results: take the full text and download attached files. Attachments exist
    only while the scan result does, so a miss is logged and is not an error."""
    stats = {"scan_results_checked": 0, "outputs_completed": 0, "attachments": 0, "attachments_skipped": 0}
    wanted = session.execute(select(PluginOutput.id, PluginOutput.plugin_id).join(
        PluginCatalog, PluginCatalog.plugin_id == PluginOutput.plugin_id).where(
        PluginOutput.truncated | PluginOutput.refers_to_attachment | PluginCatalog.has_attachments)).all()
    plugin_ids = sorted({row.plugin_id for row in wanted})
    if not plugin_ids:
        return stats
    settings = config.get_section(session, "ctem")
    limit = int(settings.get("max_attachment_mb") or DEFAULT_MAX_ATTACHMENT_MB) * 1024 * 1024
    try:
        scans = client.scan_results()[: int(settings.get("scan_results_checked") or DEFAULT_SCAN_RESULTS)]
    except ScError as err:
        log.warning("attachment_skipped", job_id=job["id"], reason=str(err))
        return stats
    filters = build_filters({"plugin_ids": ",".join(map(str, plugin_ids))})
    for scan in scans:
        stats["scan_results_checked"] += 1
        start = 0
        try:
            while True:
                response = client.scan_vulns(scan["id"], filters, start, start + PAGE_SIZE)
                records = clean(response["results"])
                for record in records:
                    _apply_scan_row(session, client, scan, record, limit, stats, job, log)
                session.commit()
                start += len(records)
                if len(records) < PAGE_SIZE or start >= int(response.get("totalRecords") or 0):
                    break
        except ScError as err:
            session.rollback()
            log.warning("attachment_skipped", job_id=job["id"], scan_result_id=scan["id"], reason=str(err))
    return stats


def _apply_scan_row(session, client, scan, record, limit, stats, job, log):
    host = host_row(record)
    output = session.execute(text("""
        SELECT o.id, o.truncated, length(o.output) AS size FROM plugin_outputs o
        JOIN host_assets h ON h.id = o.host_asset_id
        WHERE h.repository_id = :repo AND h.host_key = :host AND o.plugin_id = :plugin
          AND o.port = :port AND o.protocol = :protocol"""),
        {"repo": host["repository_id"], "host": host["host_key"], "plugin": int(record.get("pluginID") or 0),
         "port": int(record.get("port") or 0), "protocol": str(record.get("protocol") or "").upper()}).first()
    if output is None:
        return
    full = split_output(record.get("pluginText"))
    if output.truncated and TRUNCATED not in full and len(full) > output.size:
        session.execute(text("""UPDATE plugin_outputs SET output = :text, output_hash = :hash, truncated = false,
                                scan_result_id = :scan, extract_status = 'pending' WHERE id = :id"""),
                        {"text": full, "hash": hashlib.sha256(full.encode("utf-8", "replace")).hexdigest(),
                         "scan": int(scan["id"]), "id": output.id})
        stats["outputs_completed"] += 1
    for ref in attachment_refs(record.get("attachment")):
        known = session.scalar(select(PluginAttachment.id).where(
            PluginAttachment.plugin_output_id == output.id, PluginAttachment.name == ref["name"]))
        if known:
            continue
        try:
            content = client.attachment(scan["id"], ref["id"])
        except ScError as err:
            stats["attachments_skipped"] += 1
            log.warning("attachment_skipped", job_id=job["id"], scan_result_id=scan["id"], attachment=ref["name"], reason=str(err))
            continue
        too_large = len(content) > limit
        session.add(PluginAttachment(
            plugin_output_id=output.id, scan_result_id=int(scan["id"]), sc_attachment_id=ref["id"], name=ref["name"],
            mime_type=ref.get("type", ""), size=len(content), sha256=hashlib.sha256(content).hexdigest(),
            content=None if too_large else content, parse_status="too_large" if too_large else "stored"))
        session.execute(text("UPDATE plugin_outputs SET extract_status = 'pending' WHERE id = :id"), {"id": output.id})
        stats["attachments"] += 1
        log.info("attachment_fetched", job_id=job["id"], scan_result_id=scan["id"], attachment=ref["name"], size=len(content))


# ---- scheduler -----------------------------------------------------------------
def enqueue_if_due(log) -> None:
    session = new_session()
    try:
        settings = config.get_section(session, "ctem")
        if not config.get_section(session, "seccenter").get("url"):
            return
    finally:
        session.close()
    interval = int(settings.get("collect_interval_minutes", DEFAULT_INTERVAL))
    if interval <= 0:
        return
    elapsed = jobs.minutes_since_last("sc", "plugin_collect", None)
    if elapsed is not None and elapsed < interval:
        return
    if jobs.enqueue_if_idle("sc", "plugin_collect", None, "schedule"):
        log.info("plugin_collect_scheduled", interval_minutes=interval)
