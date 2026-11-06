"""Decode Tenable Identity Exposure attribute values.

TIE returns every attribute as {name, value, valueType}. `value` is a
JSON-encoded string and `valueType` says how to read it. This follows the same
rule as connectors/tie/src/query/value.ts: parse as JSON, and fall back to the
text with one layer of quotes removed when it is not valid JSON."""
import json
from datetime import datetime, timezone


def decode_value(value, value_type: str):
    if not isinstance(value, str):
        return value
    try:
        return json.loads(value)
    except ValueError:
        if len(value) >= 2 and value.startswith('"') and value.endswith('"'):
            return value[1:-1]
        return value


def decode_attributes(attributes) -> dict:
    """[{name, value, valueType}] -> {lower-case name: decoded value}."""
    decoded = {}
    for attribute in attributes or []:
        name = str(attribute.get("name") or "").lower()
        if name and name not in decoded:
            decoded[name] = decode_value(attribute.get("value"), attribute.get("valueType") or "")
    return decoded


def text(value):
    if value is None or isinstance(value, (list, dict)):
        return None
    value = str(value).strip()
    return value or None


def integer(value):
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def as_list(value) -> list:
    if value is None or value == "":
        return []
    return value if isinstance(value, list) else [value]


def timestamp(value):
    """ISO timestamps like 2022-11-10T09:43:58.3138902Z. AD's "never" values
    (year 1601, "NEVER", 0) become None."""
    value = text(value)
    if not value or not value[0].isdigit() or value.startswith("1601-"):
        return None
    try:
        head, _, fraction = value.rstrip("Z").partition(".")
        parsed = datetime.strptime(head, "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc)
        if fraction:
            parsed = parsed.replace(microsecond=int((fraction + "000000")[:6]))
        return parsed
    except ValueError:
        return None


def flags(value) -> set:
    """"PASSWD_NOTREQD WORKSTATION" or ["A", "B"] -> {"PASSWD_NOTREQD", "WORKSTATION"}."""
    if isinstance(value, list):
        parts = [str(v) for v in value]
    else:
        parts = str(value or "").replace(",", " ").replace("|", " ").split()
    return {p.strip().upper() for p in parts if p.strip()}
