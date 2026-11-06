"""PostgreSQL jsonb cannot store the NUL character (\\u0000) inside strings.
Source systems do send it now and then (binary AD attributes, plugin output)."""


def clean(value):
    """Return `value` with NUL characters removed from every string in it."""
    if isinstance(value, str):
        return value.replace("\x00", "") if "\x00" in value else value
    if isinstance(value, list):
        return [clean(v) for v in value]
    if isinstance(value, dict):
        return {clean(k): clean(v) for k, v in value.items()}
    return value
