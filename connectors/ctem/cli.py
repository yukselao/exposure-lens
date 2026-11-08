"""Try the extractor on one stored output without touching the database.

    docker compose run --rm ctem python /app/cli.py extract --output-id N --dry-run
"""
import argparse
import json

from sqlalchemy import select

from eldb import models as m
from eldb.database import new_session

import extract


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("command", choices=["extract"])
    parser.add_argument("--output-id", type=int, required=True)
    parser.add_argument("--dry-run", action="store_true", default=True)
    args = parser.parse_args()
    session = new_session()
    output = session.get(m.PluginOutput, args.output_id)
    if output is None:
        raise SystemExit(f"No plugin output {args.output_id}.")
    extractor = session.scalar(select(m.PluginCatalog.extractor).where(m.PluginCatalog.plugin_id == output.plugin_id))
    if not extractor:
        raise SystemExit(f"Plugin {output.plugin_id} has no extractor.")
    aliases = extract.host_aliases(session).get(output.host_asset_id, set())
    records = extract.parse_output(output, extractor, aliases)
    print(json.dumps({"plugin_id": output.plugin_id, "extractor": extractor, "aliases": sorted(aliases),
                      "counts": {k: len(v) for k, v in records.items()}, "records": records}, indent=2, default=str, ensure_ascii=False))


if __name__ == "__main__":
    main()
