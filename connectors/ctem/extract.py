"""ctem_extract: run the extractors over the plugin outputs that changed.

Each output is one savepoint: a parser error marks that output as failed and
the run carries on. Rows an output no longer produces are deleted."""
import re
from datetime import datetime, timezone

from sqlalchemy import func, select, text
from sqlalchemy.dialects.postgresql import insert

from eldb import models as m
from eldb.database import new_session
from elcommon import jobs

from extractors import Context, MODULES

BATCH = 200
CHUNK = 1000


def sync_rows(session, model, output, rows, keys, stamp):
    """Upsert `rows` of one output and delete what the output no longer has."""
    unique = {}
    for row in rows:
        full = {"host_asset_id": output.host_asset_id, "plugin_id": output.plugin_id,
                "source_output_id": output.id, "last_seen": stamp, **row}
        unique[tuple(full[k] for k in keys)] = full
    values = list(unique.values())
    if values:
        columns = sorted({c for row in values for c in row})
        values = [{c: row.get(c) for c in columns} for row in values]
    for i in range(0, len(values), CHUNK):
        stmt = insert(model).values(values[i:i + CHUNK])
        update = {c: stmt.excluded[c] for c in values[0] if c not in keys and c not in ("source_output_id",)}
        session.execute(stmt.on_conflict_do_update(index_elements=["source_output_id", *keys], set_=update))
    session.execute(model.__table__.delete().where(model.source_output_id == output.id, model.last_seen < stamp))
    return len(values)


def resolve_principals(session, records):
    """Give every `principal` dict in `records` its row id."""
    found = {}
    for rows in records.values():
        for row in rows:
            who = row.get("principal")
            if who:
                seen = found.get(who["natural_key"])
                if seen is None or (who.get("sid") and not seen.get("sid")):
                    found[who["natural_key"]] = who
    ids = {}
    if found:
        values = [{**w, "domain": (w["domain"] or None) and w["domain"][:255], "name": w["name"][:255]} for w in found.values()]
        stmt = insert(m.Principal).values(values)
        result = session.execute(stmt.on_conflict_do_update(index_elements=["natural_key"], set_={
            "sid": func.coalesce(m.Principal.sid, stmt.excluded.sid),
            "privileged_hint": m.Principal.privileged_hint | stmt.excluded.privileged_hint,
        }).returning(m.Principal.id, m.Principal.natural_key))
        ids = {r.natural_key: r.id for r in result}
    for rows in records.values():
        for row in rows:
            who = row.pop("principal", None)
            row["principal_id"] = ids.get(who["natural_key"]) if who else None
            row["_who"] = who


def _ioc(ioc_type, value, principal_id=None, attributes=None):
    value = str(value or "").strip()
    return {"ioc_type": ioc_type, "value": value, "value_norm": value.lower(), "principal_id": principal_id,
            "attributes": attributes or {}} if value else None


def _identity_iocs(row):
    who = row.get("_who")
    if not who or who["scope"] == "builtin":
        return []
    name = f'{who["domain"]}\\{who["name"]}' if who.get("domain") else who["name"]
    rows = [_ioc(who["kind"] if who["kind"] in ("user", "group", "computer") else "user", name, row.get("principal_id"))]
    if who.get("sid"):
        rows.append(_ioc("sid", who["sid"], row.get("principal_id")))
    return rows


def write_records(session, output, records, stamp):
    """Write one output's records to the scenario tables and to host_iocs."""
    resolve_principals(session, records)
    iocs = []
    count = 0

    software = records.get("software")
    if software is not None:
        pairs = {}
        for row in software:
            row["name"] = row["name"][:2000]
            row["version"] = (row["version"] or "")[:255]
            pairs[(row["name"].lower(), row["version"])] = row
        ids = {}
        values = [{"name": r["name"], "name_norm": k[0], "version": k[1]} for k, r in pairs.items()]
        for i in range(0, len(values), CHUNK):
            stmt = insert(m.Software).values(values[i:i + CHUNK])
            result = session.execute(stmt.on_conflict_do_update(
                index_elements=["name_norm", "version"], set_={"name": stmt.excluded.name},
            ).returning(m.Software.id, m.Software.name_norm, m.Software.version))
            ids.update({(r.name_norm, r.version): r.id for r in result})
        rows = [{"software_id": ids[k], "installed_at": r["installed_at"], "source_type": r["source_type"]} for k, r in pairs.items()]
        count += sync_rows(session, m.HostSoftware, output, rows, ["software_id"], stamp)
        iocs += [_ioc("installed_software", f'{r["name"]} {r["version"]}'.strip(), None, {"version": r["version"]}) for r in pairs.values()]

    rows = records.get("group_members")
    if rows is not None:
        data = [{"group_name": r["group_name"][:255], "group_sid": r["group_sid"], "principal_id": r["principal_id"]} for r in rows]
        count += sync_rows(session, m.HostGroupMember, output, data, ["group_name", "principal_id"], stamp)
        for r in rows:
            iocs += _identity_iocs(r)

    rows = records.get("local_accounts")
    if rows is not None:
        data = [{"principal_id": r["principal_id"], "rid": r.get("rid"), "account_type": r.get("account_type", "user"),
                 "disabled": r.get("disabled"), "flags": r.get("flags", {}), "groups": r.get("groups", []),
                 "shell": r.get("shell")} for r in rows]
        count += sync_rows(session, m.HostLocalAccount, output, data, ["principal_id"], stamp)
        for r in rows:
            iocs += _identity_iocs(r)

    rows = records.get("sessions")
    if rows is not None:
        data = [{"principal_id": r["principal_id"], "session_type": r["session_type"]} for r in rows]
        count += sync_rows(session, m.HostSession, output, data, ["principal_id", "session_type"], stamp)
        for r in rows:
            iocs += _identity_iocs(r)

    rows = records.get("services")
    if rows is not None:
        data = [{"service_name": r["service_name"][:255], "display_name": r.get("display_name"), "path": r.get("path"),
                 "start_type": r.get("start_type"), "principal_id": r["principal_id"]} for r in rows]
        count += sync_rows(session, m.HostService, output, data, ["service_name"], stamp)
        for r in rows:
            iocs.append(_ioc("service", r["service_name"], r["principal_id"], {"path": r.get("path")}))
            iocs += _identity_iocs(r)

    rows = records.get("scan_coverage")
    if rows is not None:
        data = [{k: r.get(k) for k in ("status", "protocol", "principal_id", "scan_name", "scan_time")} | {"detail": r.get("detail", {})} for r in rows]
        count += sync_rows(session, m.HostScanCoverage, output, data, ["status", "protocol"], stamp)

    rows = records.get("host_identity")
    if rows is not None:
        keep = ("hostname", "fqdn", "netbios_name", "domain", "workgroup", "sam_account_name", "domain_role", "dn")
        data = [{k: r.get(k) for k in keep} for r in rows]
        count += sync_rows(session, m.HostIdentity, output, data, [], stamp) if data else _clear(session, m.HostIdentity, output)
        for r in rows:
            iocs += [_ioc("computer", r.get("fqdn") or r.get("hostname") or r.get("netbios_name")), _ioc("domain", r.get("domain"))]

    rows = records.get("browser_extensions")
    if rows is not None:
        pairs = {(r["browser"][:64], r["extension_id"][:255]): r for r in rows}
        ids = {}
        if pairs:
            stmt = insert(m.BrowserExtension).values([{"browser": k[0], "extension_id": k[1], "name": r["name"], "description": r.get("description")}
                                                      for k, r in pairs.items()])
            result = session.execute(stmt.on_conflict_do_update(index_elements=["browser", "extension_id"], set_={
                "name": func.coalesce(func.nullif(stmt.excluded.name, "unknown"), m.BrowserExtension.name),
                "description": func.coalesce(stmt.excluded.description, m.BrowserExtension.description),
            }).returning(m.BrowserExtension.id, m.BrowserExtension.browser, m.BrowserExtension.extension_id))
            ids = {(r.browser, r.extension_id): r.id for r in result}
        data = [{"extension_ref": ids[(r["browser"][:64], r["extension_id"][:255])], "principal_id": r["principal_id"],
                 "profile_user": r.get("profile_user") or "", "version": r.get("version"), "updated_at": r.get("updated_at"),
                 "path": r.get("path") or ""} for r in rows]
        count += sync_rows(session, m.HostBrowserExtension, output, data, ["extension_ref", "profile_user", "path"], stamp)
        iocs += [_ioc("browser_extension", f'{r["browser"]}: {r["name"] or r["extension_id"]}', r["principal_id"],
                      {"extension_id": r["extension_id"], "version": r.get("version")}) for r in rows]

    iocs = [i for i in iocs if i]
    sync_rows(session, m.HostIoc, output, iocs, ["ioc_type", "value_norm"], stamp)
    return count


def _clear(session, model, output):
    session.execute(model.__table__.delete().where(model.source_output_id == output.id))
    return 0


def host_aliases(session):
    """host id -> names that mark an account on that host as local."""
    aliases = {}
    for row in session.execute(text("SELECT id, dns_name, netbios_name, host(ip) AS ip FROM host_assets")):
        names = {row.ip}
        if row.dns_name:
            names |= {row.dns_name, row.dns_name.split(".")[0]}
        if row.netbios_name:
            names.add(re.sub(r"^.*\\", "", row.netbios_name))
        aliases[row.id] = names
    # Names the host reported about itself, once host_identity has been parsed.
    for row in session.execute(text("SELECT host_asset_id, hostname, netbios_name, fqdn FROM host_identities")):
        names = aliases.setdefault(row.host_asset_id, set())
        names |= {n for n in (row.hostname, row.netbios_name) if n}
        if row.fqdn:
            names.add(row.fqdn.split(".")[0])
    return aliases


def parse_output(output, extractor, aliases):
    module = MODULES[extractor]
    return module.parse(output.plugin_id, output.output, Context(output.host_asset_id, aliases))


def _extract_batch(session, batch, extractors, aliases, stats, job, log):
    stamp = datetime.now(timezone.utc)
    for output in batch:
        extractor = extractors.get(output.plugin_id)
        if not extractor:
            output.extract_status = "skipped"
            stats["skipped"] += 1
            continue
        try:
            with session.begin_nested():
                records = parse_output(output, extractor, aliases.get(output.host_asset_id, set()))
                count = write_records(session, output, records, stamp)
            output.extract_status, output.extract_error, output.records = "ok", None, count
            output.extracted_hash, output.extractor_version = output.output_hash, MODULES[extractor].VERSION
            stats["outputs"] += 1
            stats["records"] += count
        except Exception as err:
            message = f"{type(err).__name__}: {(str(getattr(err, 'orig', err)).splitlines() or [''])[0][:300]}"
            output.extract_status, output.extract_error = "failed", message
            stats["failed"] += 1
            log.error("extract_failed", job_id=job["id"], output_id=output.id, plugin_id=output.plugin_id, error=message)


def run(job, log, rebuild=False) -> dict:
    session = new_session()
    try:
        catalog = session.execute(select(m.PluginCatalog.plugin_id, m.PluginCatalog.extractor).where(
            m.PluginCatalog.extractor.isnot(None))).all()
        extractors = {r.plugin_id: r.extractor for r in catalog}
        # A new extractor version sends its plugins back through the queue.
        for name, module in MODULES.items():
            ids = [p for p, e in extractors.items() if e == name]
            session.execute(text("""UPDATE plugin_outputs SET extract_status = 'pending'
                                    WHERE plugin_id = ANY(:ids) AND extract_status <> 'pending'
                                      AND (:all OR extractor_version IS DISTINCT FROM :version)"""),
                            {"ids": ids, "version": module.VERSION, "all": rebuild})
        session.commit()
        total = session.scalar(select(func.count()).select_from(m.PluginOutput).where(m.PluginOutput.extract_status == "pending"))
        log.info("extract_started", job_id=job["id"], pending=total, rebuild=rebuild)
        stats = {"outputs": 0, "failed": 0, "records": 0, "skipped": 0}
        # Host names first: they decide which accounts in the other plugins are local.
        first = [p for p, e in extractors.items() if e == "host_identity"]
        for condition in (m.PluginOutput.plugin_id.in_(first), m.PluginOutput.plugin_id.notin_(first)):
            aliases = host_aliases(session)
            last_id = 0
            while True:
                batch = session.scalars(select(m.PluginOutput).where(
                    m.PluginOutput.extract_status == "pending", condition, m.PluginOutput.id > last_id,
                ).order_by(m.PluginOutput.id).limit(BATCH)).all()
                if not batch:
                    break
                last_id = batch[-1].id
                _extract_batch(session, batch, extractors, aliases, stats, job, log)
                session.commit()
                jobs.progress(job["id"], done=stats["outputs"] + stats["failed"] + stats["skipped"], total=total)
                log.info("extract_batch", job_id=job["id"], **stats, total=total)
        if jobs.enqueue_if_idle("ctem", "correlate", None, "chain"):
            log.info("ctem_correlate_queued", job_id=job["id"])
        return stats
    finally:
        session.close()
