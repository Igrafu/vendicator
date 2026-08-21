"""Phase-2 temporal model PROTOTYPE, trained free on StatsBomb Open Data.

Builds structured match-state snapshots (NOT raw event text) every 5 minutes
from event streams - minute, score diff, cards, shot & xG momentum windows -
and trains a gradient-boosted temporal model to predict the final result
from any live state. This is the free stand-in for the temporal Transformer:
same inputs/outputs, upgradeable to the Transformer when an event feed is
purchased and it beats this + the analytic InPlayEngine on live Brier.

Saves: model/output/temporal_prototype.pkl + temporal_prototype_metrics.json
Run:   .venv/bin/python model/src/train_temporal_prototype.py --matches 60
"""
import argparse
import json
import pickle
from pathlib import Path

import numpy as np

from adapters import StatsBombOpen
from engines import InPlayEngine, MatchState, brier

ROOT = Path(__file__).resolve().parents[2]
OUT_MODEL = ROOT / "model" / "output" / "temporal_prototype.pkl"
OUT_METRICS = ROOT / "model" / "output" / "temporal_prototype_metrics.json"

SNAP_EVERY = 5   # minutes
CLASSES = ["H", "D", "A"]

FEATURES = ["minute_frac", "goal_diff", "total_goals", "home_reds",
            "away_reds", "shots_h_10", "shots_a_10", "xg_h_10", "xg_a_10",
            "xg_h_total", "xg_a_total"]


def snapshots_for_match(events, home_team):
    """Structured state vector every SNAP_EVERY minutes."""
    shots = []       # (minute, is_home, xg)
    goals = []       # (minute, is_home)
    reds = []        # (minute, is_home)
    max_min = 90
    for e in events:
        t = e.get("type", {}).get("name")
        minute = e.get("minute", 0)
        max_min = max(max_min, minute)
        is_home = e.get("team", {}).get("name") == home_team
        if t == "Shot":
            s = e.get("shot", {})
            shots.append((minute, is_home, s.get("statsbomb_xg") or 0.0))
            if s.get("outcome", {}).get("name") == "Goal":
                goals.append((minute, is_home))
        elif t == "Own Goal Against":
            goals.append((minute, not is_home))
        elif t in ("Bad Behaviour", "Foul Committed"):
            card = (e.get(t.lower().replace(" ", "_"), {}) or
                    e.get("foul_committed", {}) or
                    e.get("bad_behaviour", {})).get("card", {}).get("name")
            if card in ("Red Card", "Second Yellow"):
                reds.append((minute, is_home))

    hg_final = sum(1 for _, h in goals if h)
    ag_final = sum(1 for _, h in goals if not h)
    result = "H" if hg_final > ag_final else \
        "D" if hg_final == ag_final else "A"

    rows = []
    for minute in range(0, 90, SNAP_EVERY):
        hg = sum(1 for m, h in goals if h and m <= minute)
        ag = sum(1 for m, h in goals if not h and m <= minute)
        row = {
            "minute_frac": minute / 93.0,
            "goal_diff": hg - ag,
            "total_goals": hg + ag,
            "home_reds": sum(1 for m, h in reds if h and m <= minute),
            "away_reds": sum(1 for m, h in reds if not h and m <= minute),
            "shots_h_10": sum(1 for m, h, _ in shots
                              if h and minute - 10 < m <= minute),
            "shots_a_10": sum(1 for m, h, _ in shots
                              if not h and minute - 10 < m <= minute),
            "xg_h_10": sum(x for m, h, x in shots
                           if h and minute - 10 < m <= minute),
            "xg_a_10": sum(x for m, h, x in shots
                           if not h and minute - 10 < m <= minute),
            "xg_h_total": sum(x for m, h, x in shots if h and m <= minute),
            "xg_a_total": sum(x for m, h, x in shots
                              if not h and m <= minute),
            "_state": (minute, hg, ag),
        }
        rows.append(row)
    return rows, result


def build_dataset(n_matches):
    sb = StatsBombOpen()
    comps = sb.competitions()
    # biggest open league season available: La Liga (id 11); pick the season
    # with the most matches from the open list
    laliga = [c for c in comps if c["competition_id"] == 11]
    laliga.sort(key=lambda c: c["season_name"], reverse=True)
    all_rows, labels, match_ids = [], [], []
    n_done = 0
    for season in laliga:
        matches = sb.matches(11, season["season_id"])
        for m in matches:
            if n_done >= n_matches:
                break
            home = m["home_team"]["home_team_name"]
            try:
                events = sb.events(m["match_id"])
            except Exception:
                continue
            rows, result = snapshots_for_match(events, home)
            for r in rows:
                all_rows.append(r)
                labels.append(result)
                match_ids.append(m["match_id"])
            n_done += 1
        if n_done >= n_matches:
            break
    return all_rows, labels, match_ids


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--matches", type=int, default=60)
    args = ap.parse_args()

    from sklearn.ensemble import HistGradientBoostingClassifier
    print(f"Downloading StatsBomb events for ~{args.matches} matches…")
    rows, labels, match_ids = build_dataset(args.matches)
    print(f"{len(rows)} snapshots from {len(set(match_ids))} matches")

    X = np.array([[r[f] for f in FEATURES] for r in rows])
    y = np.array([CLASSES.index(v) for v in labels])
    uniq = sorted(set(match_ids))
    holdout = set(uniq[int(len(uniq) * 0.8):])   # hold out whole matches
    test_mask = np.array([m in holdout for m in match_ids])

    clf = HistGradientBoostingClassifier(max_iter=300, learning_rate=0.06)
    clf.fit(X[~test_mask], y[~test_mask])
    raw = clf.predict_proba(X[test_mask])
    probs = np.zeros((len(raw), 3))          # expand to all 3 classes even
    for j, cls in enumerate(clf.classes_):   # if training missed one
        probs[:, cls] = raw[:, j]
    live_brier = brier(probs, [CLASSES[i] for i in y[test_mask]])

    # analytic InPlayEngine baseline on the same held-out snapshots,
    # league-average pre-match rates (it knows nothing about the teams)
    engine = InPlayEngine(lam=1.45, mu=1.15)
    base_probs = []
    for r in (rows[i] for i in np.where(test_mask)[0]):
        minute, hg, ag = r["_state"]
        wp = engine.live_probs(MatchState(minute, hg, ag))["win_prob"]
        base_probs.append([wp["home"], wp["draw"], wp["away"]])
    base_brier = brier(np.array(base_probs),
                       [CLASSES[i] for i in y[test_mask]])

    OUT_MODEL.parent.mkdir(parents=True, exist_ok=True)
    with OUT_MODEL.open("wb") as f:
        pickle.dump({"model": clf, "features": FEATURES,
                     "classes": CLASSES}, f)
    metrics = {"snapshots": len(rows),
               "matches": len(set(match_ids)),
               "holdout_matches": len(holdout),
               "live_brier_prototype": round(live_brier, 4),
               "live_brier_analytic_baseline": round(base_brier, 4),
               "beats_baseline": live_brier < base_brier}
    OUT_METRICS.write_text(json.dumps(metrics, indent=2))
    print(json.dumps(metrics, indent=2))


if __name__ == "__main__":
    main()
