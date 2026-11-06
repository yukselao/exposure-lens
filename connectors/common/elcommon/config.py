"""Read and write rows of the `settings` table."""
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy import func

from eldb.models import Setting

from . import crypto


def get_section(session, section: str) -> dict:
    row = session.get(Setting, section)
    return dict(row.data or {}) if row else {}


def put_section(session, section: str, data: dict) -> None:
    stmt = insert(Setting).values(section=section, data=data)
    session.execute(stmt.on_conflict_do_update(
        index_elements=[Setting.section],
        set_={"data": stmt.excluded.data, "updated_at": func.now()}))


def secret(data: dict, name: str) -> str:
    """Decrypt `data[name + '_enc']`; empty string when it is not set."""
    return crypto.decrypt(data.get(f"{name}_enc") or "")
