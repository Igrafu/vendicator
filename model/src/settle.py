"""Settle finished predictions from free results data and start the points
flowing.

- Results source: football-data.co.uk current-season CSVs (free, updated
  after each matchday) - forced fresh download, cache bypassed.
- Every unsettled line in records/model-history.jsonl whose match has a
  result gets a settled line appended (append-only; original line stays)
  with Brier/log-loss for the ensemble's probabilities.
- The settled results are POSTed to the site (vendicator/v1/results), where
  the plugin settles member picks and awards points.

Run:  .venv/bin/python model/src/settle.py
"""
import json
from datetime import datetime, timedelta, timezone
from pathlib import Path

import requests

from run_pipeline import HISTORY, push_to_wp

ROOT = Path(__file__).resolve().parents[2]
from season_log import current_season_code

CURRENT_SEASON = current_season_code()


def fresh_results(div):
    """Current-season results, cache bypassed."""
    url = f"https://www.football-data.co.uk/mmz4281/{CURRENT_SEASON}/{div}.csv"
    r = requests.get(url, timeout=60,
                     headers={"User-Agent": "VendicatorModel/0.1"})
    r.raise_for_status()
    import csv
    import io
    out = {}
    for row in csv.DictReader(io.StringIO(
            r.content.decode("utf-8-sig", errors="ignore"))):
        try:
            date = datetime.strptime(row["Date"], "%d/%m/%Y")
            out[(row["HomeTeam"], row["AwayTeam"])] = (
                date, int(row["FTHG"]), int(row["FTAG"]))
        except (KeyError, ValueError):
            continue
    return out


def brier_1x2(probs_pct, result):
    b = 0.0
    for k, out in (("home", "H"), ("draw", "D"), ("away", "A")):
        p = float(probs_pct.get(k, 0)) / 100.0
        b += (p - (1.0 if result == out else 0.0)) ** 2
    return round(b, 4)


def main():
    lines = [json.loads(l) for l in HISTORY.read_text().splitlines()
             if l.strip()]
    settled_keys = {(r["ts"], r.get("home"), r.get("away"))
                    for r in lines if r.get("settled")}
    open_recs = [r for r in lines if not r.get("settled")
                 and (r["ts"], r.get("home"), r.get("away"))
                 not in settled_keys]

    results_cache = {}
    site_results = []
    now = datetime.now(timezone.utc)
    appended = 0
    with HISTORY.open("a") as f:
        for rec in open_recs:
            div = rec.get("league")
            if div not in ("E0", "E1", "E2", "E3", "SP1", "SP2", "I1", "I2",
                           "D1", "F1"):
                continue
            if div not in results_cache:
                try:
                    results_cache[div] = fresh_results(div)
                except Exception as e:
                    print(f"{div}: results fetch failed ({str(e)[:60]})")
                    results_cache[div] = {}
            hit = results_cache[div].get((rec["home"], rec["away"]))
            if not hit:
                continue
            date, hg, ag = hit
            pred_ts = datetime.fromisoformat(rec["ts"]).replace(tzinfo=None)
            if not (pred_ts - timedelta(days=2) <= date
                    <= pred_ts + timedelta(days=10)):
                continue  # a different meeting of the same clubs
            result = "H" if hg > ag else "D" if hg == ag else "A"
            probs = rec.get("probs") or {}
            settled = dict(rec)
            settled.update({
                "settled": True, "result": {"score": f"{hg}-{ag}",
                                            "outcome": result},
                "settled_at": now.isoformat(),
                "correction_of": f"{rec['ts']}|{rec['home']}|{rec['away']}",
                "eval": {"brier": brier_1x2(probs, result),
                         "logloss": None, "clv": None},
            })
            f.write(json.dumps(settled, separators=(",", ":")) + "\n")
            appended += 1
            site_results.append({
                "fixture": f"{rec['home']} vs {rec['away']}",
                "league": div, "result": result, "score": f"{hg}-{ag}",
                "difficulty": rec.get("difficulty") or 1.0})
            print(f"settled {rec['home']} vs {rec['away']}: {hg}-{ag} "
                  f"({result}), brier {settled['eval']['brier']}")

    print(f"{appended} predictions settled")
    if site_results:
        push_to_wp({"results": site_results}, route="results")


if __name__ == "__main__":
    main()
