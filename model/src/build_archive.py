"""Consolidated sports archive: every cached match from every source into
records/sports-archive.jsonl (append-only, deduped) plus a teams registry.

Gives the model a single continuously-updating record of results - currently
10 seasons of 10 league divisions + 6 cup competitions - so analysis can
reach back 5-10+ years. Idempotent: re-running only appends matches whose
(comp, date, home, away) key is new, so the pipeline can call it after every
data refresh.

Run:  .venv/bin/python model/src/build_archive.py
"""
import csv
import json
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DATA = ROOT / "model" / "data"
ARCHIVE = ROOT / "records" / "sports-archive.jsonl"
REGISTRY = ROOT / "records" / "teams-registry.json"

CUPS = {"ucl": "UCL", "uel": "UEL", "fac": "FAC", "eflc": "EFLC",
        "cdr": "CDR", "copit": "COPIT"}


def existing_keys():
    keys = set()
    if ARCHIVE.exists():
        for line in ARCHIVE.read_text().splitlines():
            if line.strip():
                m = json.loads(line)
                keys.add((m["comp"], m["date"], m["home"], m["away"]))
    return keys


def league_rows():
    for path in sorted(DATA.glob("fdcuk_*.csv")):
        _, season, div = path.stem.split("_")
        for r in csv.DictReader(path.open(errors="ignore")):
            try:
                raw = r["Date"]
                fmt = "%d/%m/%Y" if len(raw.split("/")[-1]) == 4 else "%d/%m/%y"
                date = datetime.strptime(raw, fmt)
                yield {"src": "football-data.co.uk", "comp": div,
                       "season": season, "date": date.strftime("%Y-%m-%d"),
                       "home": r["HomeTeam"], "away": r["AwayTeam"],
                       "hg": int(r["FTHG"]), "ag": int(r["FTAG"])}
            except (KeyError, ValueError):
                continue


def cup_rows():
    for stem, comp in CUPS.items():
        path = DATA / f"{stem}_matches.json"
        if not path.exists():
            continue
        for m in json.loads(path.read_text()):
            yield {"src": "api-football", "comp": comp,
                   "season": m["date"][:4], "date": m["date"],
                   "home": m["home"], "away": m["away"],
                   "hg": m["hg"], "ag": m["ag"]}


def main():
    keys = existing_keys()
    added = 0
    registry = {}
    if REGISTRY.exists():
        registry = json.loads(REGISTRY.read_text())

    with ARCHIVE.open("a") as out:
        for row in list(league_rows()) + list(cup_rows()):
            for team in (row["home"], row["away"]):
                reg = registry.setdefault(team, {
                    "competitions": [], "first_seen": row["date"],
                    "last_seen": row["date"], "matches": 0})
                if row["comp"] not in reg["competitions"]:
                    reg["competitions"].append(row["comp"])
                reg["first_seen"] = min(reg["first_seen"], row["date"])
                reg["last_seen"] = max(reg["last_seen"], row["date"])

            key = (row["comp"], row["date"], row["home"], row["away"])
            if key in keys:
                continue
            keys.add(key)
            registry[row["home"]]["matches"] += 1
            registry[row["away"]]["matches"] += 1
            out.write(json.dumps(row, separators=(",", ":")) + "\n")
            added += 1

    REGISTRY.write_text(json.dumps(registry, indent=1, sort_keys=True))
    span = [k[1] for k in keys]
    print(f"Archive: {len(keys)} matches ({min(span)} → {max(span)}), "
          f"+{added} new; {len(registry)} teams in registry")


if __name__ == "__main__":
    main()
