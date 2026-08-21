"""Six-engine pipeline orchestrator + walk-forward backtest.

DATA -> FEATURE ENGINE -> specialists (A tabular / B temporal / C graph /
D statistical+Bayesian) -> ENSEMBLE (stacking) -> calibration ->
PROBABILITY ENGINE -> MONTE CARLO -> value engine -> records + site payload.

Run:  .venv/bin/python model/src/run_pipeline.py --league E0
"""
import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from adapters import FootballDataCoUk
from engines import (BayesianStrengths, DixonColes, EloModel, InPlayEngine,
                     MatchState, StackedEnsemble, TabularEnsemble,
                     TABULAR_AVAILABLE, as_percentages, brier,
                     bookmaker_suggestion, log_loss_score, markets_from_grid)
from engines.graph import TacticalReport
from features import FEATURE_COLS, build_match_table, normalise_understat
from llm_explain import explanation_context

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / "model" / "output" / "predictions.json"
HISTORY = ROOT / "records" / "model-history.jsonl"
UNIFORM = np.array([1 / 3, 1 / 3, 1 / 3])

FootballDataCoUk.CACHE = ROOT / "model" / "data"

UNDERSTAT_LEAGUE = {"E0": "EPL", "SP1": "La_liga", "D1": "Bundesliga",
                    "I1": "Serie_A", "F1": "Ligue_1"}
DEFAULT_SEASONS = {
    "E0": "1819,1920,2021,2122,2223,2324,2425,2526",
    "E1": "2122,2223,2324,2425,2526",
    "E2": "2122,2223,2324,2425,2526",
    "E3": "2122,2223,2324,2425,2526",
    "SP1": "2223,2324,2425",
    "SP2": "2122,2223,2324,2425,2526",
    "D1": "2223,2324,2425",
    "I1": "2223,2324,2425",
    "I2": "2122,2223,2324,2425,2526",
    "F1": "2223,2324,2425",
}


def understat_lookup(league="EPL"):
    """Lookup from cached Understat files (model/data/): nearest same-pairing
    match within 4 days -> (xg_home, xg_away)."""
    pairs = {}
    for path in sorted((ROOT / "model" / "data").glob(
            f"understat_{league}_*.json")):
        for g in json.loads(path.read_text()):
            key = (normalise_understat(g["home"]),
                   normalise_understat(g["away"]))
            date = datetime.strptime(g["date"], "%Y-%m-%d")
            pairs.setdefault(key, []).append(
                (date, g["xg_home"], g["xg_away"]))

    def lookup(home, away, date):
        best = None
        for d, xh, xa in pairs.get((home, away), []):
            gap = abs((d - date).days)
            if gap <= 4 and (best is None or gap < best[0]):
                best = (gap, xh, xa)
        return (best[1], best[2]) if best else None
    return lookup if pairs else None


CUP_CODES = ("UCL", "UEL", "FAC", "EFLC", "CDR", "COPIT")


def load_table(div, seasons):
    if div in CUP_CODES:  # cached API-Football cup fixtures
        cached = json.loads(
            (ROOT / "model" / "data" / f"{div.lower()}_matches.json")
            .read_text())
        rows = [{"Date": datetime.strptime(m["date"], "%Y-%m-%d")
                 .strftime("%d/%m/%Y"),
                 "HomeTeam": m["home"], "AwayTeam": m["away"],
                 "FTHG": m["hg"], "FTAG": m["ag"]} for m in cached]
        return build_match_table(rows)
    src = FootballDataCoUk()
    rows = []
    for s in seasons:
        rows += src.season_csv(s, div)
    us = UNDERSTAT_LEAGUE.get(div)
    return build_match_table(rows,
                             xg_lookup=understat_lookup(us) if us else None)


def xg_poisson_probs(df):
    """xG model: rolling xG for / xGA against -> Poisson 1X2 probabilities."""
    from scipy.stats import poisson as pois
    out = []
    for _, m in df.iterrows():
        lam = np.nanmean([m.get("h_xg_avg"), m.get("a_xga_avg")]) * 1.1
        mu = np.nanmean([m.get("a_xg_avg"), m.get("h_xga_avg")]) * 0.95
        if not (np.isfinite(lam) and np.isfinite(mu)):
            out.append(UNIFORM.tolist())
            continue
        grid = np.outer(pois.pmf(range(9), lam), pois.pmf(range(9), mu))
        grid /= grid.sum()
        out.append([float(np.tril(grid, -1).sum()), float(np.trace(grid)),
                    float(np.triu(grid, 1).sum())])
    return np.array(out)


def walk_forward_probs(df, train_end):
    """Leak-free base-model probabilities for every match.

    Elo: naturally walk-forward (probability computed before each update).
    Dixon-Coles + Bayesian: fitted only on matches before `train_end`.
    """
    elo = EloModel()
    elo_probs, elo_diffs = [], []
    for _, m in df.iterrows():
        p = elo.probs(m["home"], m["away"])
        elo_probs.append([p["home"], p["draw"], p["away"]])
        elo_diffs.append(elo.rating(m["home"]) - elo.rating(m["away"]))
        elo.update(m["home"], m["away"], m["hg"], m["ag"])

    train = df.iloc[:train_end]
    today = df["date"].max()
    dc = DixonColes().fit([
        {"home": m["home"], "away": m["away"], "hg": m["hg"], "ag": m["ag"],
         "days_ago": (today - m["date"]).days}
        for _, m in train.iterrows()])
    bayes = BayesianStrengths().fit(
        [(m["home"], m["away"], m["hg"], m["ag"])
         for _, m in train.iterrows()])

    dc_probs, dc_lams, dc_mus, bayes_probs = [], [], [], []
    known = set(dc.teams)
    for _, m in df.iterrows():
        if m["home"] in known and m["away"] in known:
            grid, lam, mu = dc.score_grid(m["home"], m["away"])
            mk = markets_from_grid(grid)["1x2"]
            dc_probs.append([mk["home"], mk["draw"], mk["away"]])
            dc_lams.append(lam)
            dc_mus.append(mu)
            bp = bayes.probs_fast(m["home"], m["away"])
            bayes_probs.append([bp["home"], bp["draw"], bp["away"]])
        else:  # promoted team unseen in training window
            dc_probs.append(UNIFORM.tolist())
            dc_lams.append(np.nan)
            dc_mus.append(np.nan)
            bayes_probs.append(UNIFORM.tolist())

    df = df.copy()
    df["elo_diff"] = elo_diffs
    df["dc_lambda"] = dc_lams
    df["dc_mu"] = dc_mus
    base = {"elo": np.array(elo_probs), "dixon_coles": np.array(dc_probs),
            "bayesian": np.array(bayes_probs),
            "xg_poisson": xg_poisson_probs(df)}
    if "mkt_p_home" in df.columns:  # absent for odds-less sources (UCL/UEL)
        mkt = df[["mkt_p_home", "mkt_p_draw", "mkt_p_away"]].to_numpy(float)
        base["market"] = np.where(np.isnan(mkt), UNIFORM, mkt)
    else:
        base["market"] = np.tile(UNIFORM, (len(df), 1))
    return df, base, {"elo": elo, "dc": dc, "bayes": bayes}


def backtest(df, base):
    """60/20/20 chronological split: base-train / meta-fit / evaluation.

    A draw-specialist head (binary GBM on 'is this a draw?') feeds the
    stacker as a context feature - draws are the classic weak spot."""
    from sklearn.ensemble import HistGradientBoostingClassifier
    n = len(df)
    i_meta, i_test = int(n * 0.6), int(n * 0.8)
    y = df["result"].to_numpy()

    X = df[[c for c in FEATURE_COLS if c in df.columns]].to_numpy(float)
    tab = TabularEnsemble().fit(X[:i_meta], y[:i_meta])
    base = dict(base)
    tab_all = tab.predict_proba(X)
    base["tabular"] = tab_all

    draw_head = HistGradientBoostingClassifier(max_iter=250,
                                               learning_rate=0.05)
    draw_head.fit(np.nan_to_num(X[:i_meta]), (y[:i_meta] == "D"))
    p_draw = draw_head.predict_proba(np.nan_to_num(X))[:, 1].reshape(-1, 1)

    meta_slice = slice(i_meta, i_test)
    test_slice = slice(i_test, n)
    stack = StackedEnsemble().fit(
        {k: v[meta_slice] for k, v in base.items()}, y[meta_slice],
        context=p_draw[meta_slice])
    # second stacker without market input, for fixtures with no odds yet
    no_mkt = {k: v for k, v in base.items() if k != "market"}
    stack_no_market = StackedEnsemble().fit(
        {k: v[meta_slice] for k, v in no_mkt.items()}, y[meta_slice],
        context=p_draw[meta_slice])

    final = stack.predict_proba({k: v[test_slice] for k, v in base.items()},
                                context=p_draw[test_slice])
    report = {"n_test": n - i_test,
              "tabular_members": list(tab.models),
              "trust_weights": stack.trust_weights(),
              "scores": {}}
    for name, probs in [*[(k, v[test_slice]) for k, v in base.items()],
                        ("STACKED+CALIBRATED", final)]:
        report["scores"][name] = {
            "brier": round(brier(np.asarray(probs), y[test_slice]), 4),
            "log_loss": round(log_loss_score(np.asarray(probs),
                                             y[test_slice]), 4)}
    final_nm = stack_no_market.predict_proba(
        {k: v[test_slice] for k, v in no_mkt.items()},
        context=p_draw[test_slice])
    report["scores"]["STACKED_NO_MARKET"] = {
        "brier": round(brier(final_nm, y[test_slice]), 4),
        "log_loss": round(log_loss_score(final_nm, y[test_slice]), 4)}
    return report, stack, stack_no_market, tab, draw_head


def demo_prediction(df, models, stack, tab, draw_head, home, away, league):
    """Full site payload for one fixture, all percentages."""
    dc, elo, bayes = models["dc"], models["elo"], models["bayes"]
    grid, lam, mu = dc.score_grid(home, away)
    markets = markets_from_grid(grid)
    ep = elo.probs(home, away)
    bp = bayes.probs_with_uncertainty(home, away)

    feat_row = np.full((1, len([c for c in FEATURE_COLS
                                if c in df.columns])), np.nan)
    # no odds known for a future fixture -> use the no-market stacker
    base = {"elo": np.array([[ep["home"], ep["draw"], ep["away"]]]),
            "dixon_coles": np.array([[markets["1x2"]["home"],
                                      markets["1x2"]["draw"],
                                      markets["1x2"]["away"]]]),
            "bayesian": np.array([[bp["home"], bp["draw"], bp["away"]]]),
            "xg_poisson": UNIFORM.reshape(1, 3),
            "tabular": tab.predict_proba(feat_row)}
    p_draw = draw_head.predict_proba(np.nan_to_num(feat_row))[:, 1]
    final = stack.predict_proba(base, context=p_draw.reshape(-1, 1))[0]
    final_probs = {"home": float(final[0]), "draw": float(final[1]),
                   "away": float(final[2])}

    inplay = InPlayEngine(lam, mu).live_probs(MatchState(60, 1, 0))
    notes = TacticalReport.from_basic_stats(
        {"shots_avg": 14, "form_pts": 2.0}, {"ga_avg": 1.4})
    difficulty = bayes.difficulty_multiplier(home, away)

    return {
        "generated": datetime.now(timezone.utc).isoformat(),
        "league": league,
        "fixture": f"{home} vs {away}",
        "expected_goals": {"home": round(lam, 2), "away": round(mu, 2)},
        "final_calibrated": as_percentages(final_probs),
        "markets_dixon_coles": as_percentages(markets),
        "uncertainty_band_home_pct": [round(x * 100, 1)
                                      for x in bp["home_ci90"]],
        "reward_difficulty_multiplier": difficulty,
        "in_play_demo_60min_1_0": as_percentages(
            {k: v for k, v in inplay.items()
             if isinstance(v, dict)}),
        "tactical_notes": notes,
        "explanation_context": explanation_context(
            f"{home} vs {away}", final_probs,
            {"elo": ep,
             "dixon_coles": markets["1x2"],
             "bayesian": {k: bp[k] for k in ("home", "draw", "away")}},
            stack.trust_weights(), tactical_notes=notes,
            uncertainty=bp["uncertainty"]),
    }


def append_history(payload, league):
    rec = {"ts": payload["generated"], "league": league,
           "home": payload["fixture"].split(" vs ")[0],
           "away": payload["fixture"].split(" vs ")[1],
           "model": "ensemble", "market": "1x2",
           "prediction": payload["final_calibrated"],
           "probs": payload["final_calibrated"],
           "result": None, "settled": False, "eval": {}}
    with HISTORY.open("a") as f:
        f.write(json.dumps(rec, separators=(",", ":")) + "\n")


def run_league(league, seasons=None):
    seasons = (seasons or DEFAULT_SEASONS.get(league, "2223,2324,2425")
               ).split(",")
    df = load_table(league, seasons)
    print(f"Loaded {len(df)} matches ({league})")
    df, base, models = walk_forward_probs(df, int(len(df) * 0.6))
    return df, base, models, backtest(df, base)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--league", default="E0",
                    help="one league, or comma list for a backtest sweep")
    ap.add_argument("--seasons")
    ap.add_argument("--home")
    ap.add_argument("--away")
    ap.add_argument("--no-history", action="store_true")
    args = ap.parse_args()

    print(f"Tabular libraries available: {sorted(TABULAR_AVAILABLE)}")
    leagues = args.league.split(",")

    if len(leagues) > 1:  # per-league backtest sweep -> report file
        sweep = {}
        for lg in leagues:
            print(f"\n=== {lg} ===")
            *_, (report, *_models) = run_league(lg, args.seasons)
            sweep[lg] = report
            print(json.dumps(report["scores"], indent=2))
        out = ROOT / "model" / "output" / "backtest_report.json"
        out.write_text(json.dumps(sweep, indent=2))
        print(f"\nWrote {out}")
        return

    df, base, models, (report, stack, stack_no_market, tab, draw_head) = \
        run_league(leagues[0], args.seasons)
    print(json.dumps(report, indent=2))

    if args.home and args.away:
        payload = demo_prediction(df, models, stack_no_market, tab, draw_head,
                                  args.home, args.away, leagues[0])
        OUTPUT.parent.mkdir(parents=True, exist_ok=True)
        OUTPUT.write_text(json.dumps(payload, indent=2))
        if not args.no_history:
            append_history(payload, args.league)
        print(f"\nWrote {OUTPUT}")
        print(json.dumps({k: payload[k] for k in
                          ("fixture", "final_calibrated",
                           "uncertainty_band_home_pct",
                           "reward_difficulty_multiplier")}, indent=2))


if __name__ == "__main__":
    main()
