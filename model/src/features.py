"""FEATURE ENGINE.

Turns raw match rows (football-data.co.uk schema) into model-ready features.
Market data is a model INPUT here, not just an end-of-line comparison:
de-vigged bookmaker probabilities, odds disagreement across books, and
opening-vs-closing movement all become features the meta-model can weigh.
"""
from datetime import datetime

import numpy as np
import pandas as pd

from engines.value import devig_proportional

FORM_N = 5


def _to_float(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return np.nan


def market_features(row):
    """Bookmaker/exchange signals as inputs (point: 'make market a model
    input'). Uses B365 + Pinnacle + market average where present."""
    out = {}
    b365 = {k: _to_float(row.get(f"B365{k}")) for k in ("H", "D", "A")}
    if all(v and v > 1 for v in b365.values()):
        fair = devig_proportional({"H": b365["H"], "D": b365["D"],
                                   "A": b365["A"]})
        out["mkt_p_home"], out["mkt_p_draw"], out["mkt_p_away"] = \
            fair["H"], fair["D"], fair["A"]
    # closing columns (B365CH...) exist in recent seasons -> odds movement
    close = {k: _to_float(row.get(f"B365C{k}")) for k in ("H", "D", "A")}
    if all(v and v > 1 for v in close.values()) and "mkt_p_home" in out:
        cfair = devig_proportional({"H": close["H"], "D": close["D"],
                                    "A": close["A"]})
        out["mkt_move_home"] = cfair["H"] - out["mkt_p_home"]
        out["mkt_move_away"] = cfair["A"] - out["mkt_p_away"]
    # disagreement between books (B365 vs Pinnacle vs avg)
    homes = [_to_float(row.get(c)) for c in ("B365H", "PSH", "AvgH", "MaxH")]
    homes = [h for h in homes if h and h > 1]
    if len(homes) >= 2:
        out["mkt_disagreement"] = float(np.std([1 / h for h in homes]))
    return out


def build_match_table(rows):
    """rows: list of csv dicts -> chronological DataFrame with rolling-form,
    rolling attack/defence, shots, rest days and market features per match."""
    recs = []
    for r in rows:
        try:
            date = datetime.strptime(r["Date"], "%d/%m/%Y")
            hg, ag = int(r["FTHG"]), int(r["FTAG"])
        except (KeyError, ValueError):
            continue
        rec = {"date": date, "home": r["HomeTeam"], "away": r["AwayTeam"],
               "hg": hg, "ag": ag,
               "hs": _to_float(r.get("HS")), "as_": _to_float(r.get("AS")),
               "hst": _to_float(r.get("HST")), "ast": _to_float(r.get("AST")),
               "result": "H" if hg > ag else "D" if hg == ag else "A"}
        rec.update(market_features(r))
        recs.append(rec)
    df = pd.DataFrame(recs).sort_values("date").reset_index(drop=True)

    hist = {}   # team -> list of dicts (chronological)
    feats = []
    for _, m in df.iterrows():
        f = {}
        for side, team, opp_prefix in (("h", m["home"], "a"),
                                       ("a", m["away"], "h")):
            past = hist.get(team, [])[-FORM_N:]
            if past:
                f[f"{side}_form_pts"] = sum(p["pts"] for p in past) / len(past)
                f[f"{side}_gf_avg"] = sum(p["gf"] for p in past) / len(past)
                f[f"{side}_ga_avg"] = sum(p["ga"] for p in past) / len(past)
                f[f"{side}_shots_avg"] = np.nanmean(
                    [p["shots"] for p in past])
                f[f"{side}_sot_avg"] = np.nanmean([p["sot"] for p in past])
                f[f"{side}_rest_days"] = (m["date"] - past[-1]["date"]).days
            else:
                f[f"{side}_form_pts"] = np.nan
        feats.append(f)

        for team, gf, ga, shots, sot in (
                (m["home"], m["hg"], m["ag"], m["hs"], m["hst"]),
                (m["away"], m["ag"], m["hg"], m["as_"], m["ast"])):
            pts = 3 if gf > ga else 1 if gf == ga else 0
            hist.setdefault(team, []).append(
                {"date": m["date"], "gf": gf, "ga": ga, "pts": pts,
                 "shots": shots, "sot": sot})

    return pd.concat([df, pd.DataFrame(feats)], axis=1)


FEATURE_COLS = [
    "h_form_pts", "h_gf_avg", "h_ga_avg", "h_shots_avg", "h_sot_avg",
    "h_rest_days",
    "a_form_pts", "a_gf_avg", "a_ga_avg", "a_shots_avg", "a_sot_avg",
    "a_rest_days",
    "mkt_p_home", "mkt_p_draw", "mkt_p_away",
    "mkt_move_home", "mkt_move_away", "mkt_disagreement",
    # appended by the pipeline from the statistical engine:
    "elo_diff", "dc_lambda", "dc_mu",
]
