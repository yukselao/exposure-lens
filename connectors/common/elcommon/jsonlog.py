"""JSON-lines logging to stdout and to a rotating file.

Every record has `timestamp`, `level`, `connector` and `event`, plus the fields
of the event. Never pass credentials, tokens or raw source records as fields.
"""
import json
import logging
import logging.handlers
import os
import sys
import traceback
from datetime import datetime, timezone


class JsonFormatter(logging.Formatter):
    def __init__(self, connector: str):
        super().__init__()
        self.connector = connector

    def format(self, record: logging.LogRecord) -> str:
        entry = {
            "timestamp": datetime.fromtimestamp(record.created, timezone.utc)
            .isoformat(timespec="milliseconds").replace("+00:00", "Z"),
            "level": record.levelname.lower(),
            "connector": self.connector,
            "event": record.getMessage(),
        }
        entry.update(getattr(record, "fields", None) or {})
        if record.exc_info:
            exc_type, exc, _tb = record.exc_info
            entry["error_type"] = exc_type.__name__ if exc_type else None
            entry.setdefault("error", str(exc)[:500])
            # Stack frames only: the exception text can carry SQL and row data.
            entry["traceback"] = "".join(traceback.format_tb(record.exc_info[2]))[-3000:]
        return json.dumps(entry, ensure_ascii=False, default=str)


class EventLogger:
    """`log.info("sc_page_fetched", job_id=3, fetched=1000)`."""

    def __init__(self, logger: logging.Logger):
        self._logger = logger

    def _log(self, level, event, exc_info, fields):
        self._logger.log(level, event, exc_info=exc_info, extra={"fields": fields})

    def debug(self, event, **fields):
        self._log(logging.DEBUG, event, False, fields)

    def info(self, event, **fields):
        self._log(logging.INFO, event, False, fields)

    def warning(self, event, **fields):
        self._log(logging.WARNING, event, False, fields)

    def error(self, event, **fields):
        self._log(logging.ERROR, event, False, fields)

    def exception(self, event, **fields):
        """Like error(), and adds the current exception and its traceback."""
        self._log(logging.ERROR, event, True, fields)


def setup(connector: str) -> EventLogger:
    """Configure logging from LOG_LEVEL and LOG_FILE and return the logger."""
    logger = logging.getLogger(connector)
    logger.setLevel(os.environ.get("LOG_LEVEL", "INFO").upper())
    logger.propagate = False
    logger.handlers.clear()
    formatter = JsonFormatter(connector)

    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    logger.addHandler(stream)

    event_logger = EventLogger(logger)
    path = os.environ.get("LOG_FILE")
    if path:
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            handler = logging.handlers.RotatingFileHandler(
                path, maxBytes=int(os.environ.get("LOG_MAX_BYTES", 10 * 1024 * 1024)),
                backupCount=int(os.environ.get("LOG_BACKUP_COUNT", 5)), encoding="utf-8")
            handler.setFormatter(formatter)
            logger.addHandler(handler)
        except OSError as err:
            event_logger.error("log_file_unavailable", path=path, error=str(err))
    return event_logger
