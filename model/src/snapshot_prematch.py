"""Daily pre-match signal snapshots -> our own injury/lineup history.

The key fact about injury/lineup data: NO free source offers historical
snapshots (what was known BEFORE each match), which is exactly what a
backtest needs. The fix is to build our own: run this daily (cron) and it
logs dated injury snapshots per enabled league into
records/injury-history.jsonl. After a season of logging, the ensemble can
train on real pre-match injury burden - the main lever left against the
closing line.

Sources: API-Football injuries endpoint (legit, keyed). Free plan covers
seasons 2021-2023; current-season snapshots need the Pro tier - the script
logs whatever the key can reach and reports what it could not.

Run:  .venv/bin/python model/src/snapshot_prematch.py --season 2026
"""
import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

from live_features import injury_counts

ROOT = Path(__file__).resolve().parents[2]
LOG = ROOT / "records" / "injury-history.jsonl"

LEAGUE_IDS = {"E0": 39, "E1": 40, "E2": 41, "E3": 42, "SP1": 140,
              "I1": 135, "D1": 78, "F1": 61, "UCL": 2, "UEL": 3}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--season", type=int,
                    default=datetime.now(timezone.utc).year)
    ap.add_argument("--leagues", default="E0")
    args = ap.parse_args()

    ts = datetime.now(timezone.utc).isoformat()
    with LOG.open("a") as f:
        for code in args.leagues.split(","):
            lid = LEAGUE_IDS.get(code)
            if not lid:
                print(f"{code}: no API-Football id mapped, skipped")
                continue
            try:
                counts = injury_counts(lid, args.season)
            except Exception as e:
                print(f"{code} season {args.season}: unavailable "
                      f"({str(e)[:80]}) - likely needs Pro tier for the "
                      "current season")
                continue
            f.write(json.dumps({"ts": ts, "league": code,
                                "season": args.season,
                                "injuries_by_team": counts},
                               separators=(",", ":")) + "\n")
            print(f"{code} season {args.season}: logged "
                  f"{sum(counts.values())} injury listings "
                  f"across {len(counts)} teams")


if __name__ == "__main__":
    main()
