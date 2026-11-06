"""Engine and session factory, configured from PG_* environment variables."""
import os
from urllib.parse import quote_plus

from sqlalchemy import MetaData, create_engine
from sqlalchemy.orm import declarative_base, sessionmaker

NAMING_CONVENTION = {
    "ix": "ix_%(table_name)s_%(column_0_N_name)s",
    "uq": "uq_%(table_name)s_%(column_0_N_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}


def database_url() -> str:
    url = os.environ.get("DATABASE_URL")
    if url:
        return url
    user = quote_plus(os.environ.get("PG_USER", "exposure_lens"))
    password = quote_plus(os.environ.get("PG_PASSWORD", ""))
    host = os.environ.get("PG_HOST", "postgres")
    port = os.environ.get("PG_PORT", "5432")
    name = os.environ.get("PG_DB", "exposure_lens")
    return f"postgresql+psycopg2://{user}:{password}@{host}:{port}/{name}"


Base = declarative_base(metadata=MetaData(naming_convention=NAMING_CONVENTION))

_engine = None
_Session = None


def get_engine():
    global _engine
    if _engine is None:
        _engine = create_engine(database_url(), pool_pre_ping=True, pool_size=3, max_overflow=2,
                                hide_parameters=True)
    return _engine


def new_session():
    """A new ORM session. The caller commits, rolls back and closes it."""
    global _Session
    if _Session is None:
        _Session = sessionmaker(bind=get_engine(), expire_on_commit=False)
    return _Session()
