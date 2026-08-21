"""Season log - every match this season, played and upcoming.

records/season-log.jsonl holds one line per match with:
  competition_type: 'league' | 'cup' | 'friendly'
  status: 'played' | 'upcoming'
  gameweek: derived matchday number within the competition

Friendlies are logged with the SAME schema but tagged 'friendly', and every
aggregate reports their totals separately so pre-season and international
friendlies never contaminate league or cup form.

Run:  .venv/bin/python model/src/season_log.py
"""
import json
from datetime import datetime, timezone
from pathlib import Path

from adapters import FootballDataCoUk
from teams import canonical

ROOT = Path(__file__).resolve().parents[2]
LOG = ROOT / "records" / "season-log.jsonl"
SUMMARY = ROOT / "records" / "season-summary.json"
def current_season_code(today=None):
    """football-data.co.uk season code for the season in progress.
    Seasons run Jul->Jun, so August 2026 is '2627'."""
    today = today or datetime.now(timezone.utc)
    start = today.year if today.month >= 7 else today.year - 1
    return f"{start % 100:02d}{(start + 1) % 100:02d}"


def season_label(code=None):
    code = code or current_season_code()
    return f"20{code[:2]}/{code[2:]}"


CURRENT = current_season_code()

LEAGUE_DIVS = ("E0", "E1", "E2", "E3", "SP1", "SP2", "I1", "I2", "D1", "F1")
CUP_FILES = {"ucl": "UCL", "uel": "UEL", "fac": "FAC", "eflc": "EFLC",
             "cdr": "CDR", "copit": "COPIT"}
FRIENDLY_FILES = {"friendlies": "FRIENDLY"}


def _parse(d):
    for fmt in ("%d/%m/%Y", "%d/%m/%y", "%Y-%m-%d"):
        try:
            return datetime.strptime(d, fmt)
        except (ValueError, TypeError):
            continue
    return None


def gameweeks(matches):
    """Assign a matchday number per competition: matches are grouped into
    weeks by date order, which matches how leagues actually number them."""
    by_comp = {}
    for m in matches:
        by_comp.setdefault(m["competition"], []).append(m)
    for comp, rows in by_comp.items():
        rows.sort(key=lambda r: r["date"])
        week, last = 0, None
        for r in rows:
            d = _parse(r["date"])
            if last is None or (d - last).days >= 3:
                week += 1
                last = d
            r["gameweek"] = week
    return matches


def collect():
    src = FootballDataCoUk()
    src.CACHE = ROOT / "model" / "data"
    out = []
    for div in LEAGUE_DIVS:
        try:
            rows = src.season_csv(CURRENT, div)
        except Exception:
            continue
        for r in rows:
            d = _parse(r.get("Date"))
            if not d:
                continue
            try:
                hg, ag = int(r["FTHG"]), int(r["FTAG"])
                status, score = "played", f"{hg}-{ag}"
            except (KeyError, ValueError):
                status, score = "upcoming", None
            out.append({"competition": div, "competition_type": "league",
                        "season": CURRENT, "date": d.strftime("%Y-%m-%d"),
                        "home": canonical(r.get("HomeTeam")),
                        "away": canonical(r.get("AwayTeam")),
                        "status": status, "score": score})

    data_dir = ROOT / "model" / "data"
    for stem, comp in list(CUP_FILES.items()) + list(FRIENDLY_FILES.items()):
        f = data_dir / f"{stem}_matches.json"
        if not f.exists():
            continue
        ctype = "friendly" if comp == "FRIENDLY" else "cup"
        for m in json.loads(f.read_text()):
            d = _parse(m.get("date"))
            if not d or d.year < int("20" + CURRENT[:2]):
                continue
            out.append({"competition": comp, "competition_type": ctype,
                        "season": CURRENT, "date": d.strftime("%Y-%m-%d"),
                        "home": canonical(m.get("home")),
                        "away": canonical(m.get("away")),
                        "status": "played",
                        "score": f"{m.get('hg')}-{m.get('ag')}"})

    # upcoming fixtures feed (free) - not yet in the results CSVs
    try:
        import requests
        import csv as _csv
        import io as _io
        r = requests.get("https://www.football-data.co.uk/fixtures.csv",
                         timeout=30)
        r.raise_for_status()
        seen = {(m["competition"], m["date"], m["home"], m["away"])
                for m in out}
        for row in _csv.DictReader(_io.StringIO(
                r.content.decode("utf-8-sig", errors="ignore"))):
            d = _parse(row.get("Date"))
            if not d or row.get("Div") not in LEAGUE_DIVS:
                continue
            rec = {"competition": row["Div"], "competition_type": "league",
                   "season": CURRENT, "date": d.strftime("%Y-%m-%d"),
                   "home": canonical(row.get("HomeTeam")),
                   "away": canonical(row.get("AwayTeam")),
                   "status": "upcoming", "score": None}
            key = (rec["competition"], rec["date"], rec["home"], rec["away"])
            if key not in seen:
                seen.add(key)
                out.append(rec)
    except Exception as e:
        print(f"fixtures feed: {str(e)[:60]}")

    return gameweeks(out)


def main():
    matches = collect()
    LOG.write_text("".join(json.dumps(m, separators=(",", ":")) + "\n"
                           for m in sorted(matches,
                                           key=lambda m: (m["date"],
                                                          m["competition"]))))
    summary = {"generated": datetime.now(timezone.utc).isoformat(),
               "season": CURRENT,
               # the three streams are always reported separately, so
               # friendly totals never mix into league or cup form
               "by_type": {"league": {"played": 0, "upcoming": 0},
                           "cup": {"played": 0, "upcoming": 0},
                           "friendly": {"played": 0, "upcoming": 0}},
               "by_competition": {}}
    for m in matches:
        t = summary["by_type"].setdefault(
            m["competition_type"], {"played": 0, "upcoming": 0})
        t[m["status"]] += 1
        c = summary["by_competition"].setdefault(
            m["competition"], {"type": m["competition_type"],
                               "played": 0, "upcoming": 0, "gameweeks": 0})
        c[m["status"]] += 1
        c["gameweeks"] = max(c["gameweeks"], m.get("gameweek", 0))
    SUMMARY.write_text(json.dumps(summary, indent=1))
    line = ", ".join(f"{k}: {v['played']} played / {v['upcoming']} upcoming"
                     for k, v in summary["by_type"].items())
    print(f"Season log: {len(matches)} matches ({line}) -> {LOG}")


if __name__ == "__main__":
    main()
