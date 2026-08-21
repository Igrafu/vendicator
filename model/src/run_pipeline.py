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
from features import FEATURE_COLS, build_match_table
from llm_explain import explanation_context

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / "model" / "output" / "predictions.json"
HISTORY = ROOT / "records" / "model-history.jsonl"
UNIFORM = np.array([1 / 3, 1 / 3, 1 / 3])


def load_table(div, seasons):
    src = FootballDataCoUk()
    rows = []
    for s in seasons:
        rows += src.season_csv(s, div)
    return build_match_table(rows)


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
            bp = bayes.probs_with_uncertainty(m["home"], m["away"],
                                              n_samples=1500)
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
            "bayesian": np.array(bayes_probs)}
    mkt = df[["mkt_p_home", "mkt_p_draw", "mkt_p_away"]].to_numpy(float)
    base["market"] = np.where(np.isnan(mkt), UNIFORM, mkt)
    return df, base, {"elo": elo, "dc": dc, "bayes": bayes}


def backtest(df, base):
    """60/20/20 chronological split: base-train / meta-fit / evaluation."""
    n = len(df)
    i_meta, i_test = int(n * 0.6), int(n * 0.8)
    y = df["result"].to_numpy()

    X = df[[c for c in FEATURE_COLS if c in df.columns]].to_numpy(float)
    tab = TabularEnsemble().fit(X[:i_meta], y[:i_meta])
    base = dict(base)
    tab_all = tab.predict_proba(X)
    base["tabular"] = tab_all

    meta_slice = slice(i_meta, i_test)
    test_slice = slice(i_test, n)
    stack = StackedEnsemble().fit(
        {k: v[meta_slice] for k, v in base.items()}, y[meta_slice])
    # second stacker without market input, for fixtures with no odds yet
    no_mkt = {k: v for k, v in base.items() if k != "market"}
    stack_no_market = StackedEnsemble().fit(
        {k: v[meta_slice] for k, v in no_mkt.items()}, y[meta_slice])

    final = stack.predict_proba({k: v[test_slice] for k, v in base.items()})
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
        {k: v[test_slice] for k, v in no_mkt.items()})
    report["scores"]["STACKED_NO_MARKET"] = {
        "brier": round(brier(final_nm, y[test_slice]), 4),
        "log_loss": round(log_loss_score(final_nm, y[test_slice]), 4)}
    return report, stack, stack_no_market, tab


def demo_prediction(df, models, stack, tab, home, away, league):
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
            "tabular": tab.predict_proba(feat_row)}
    final = stack.predict_proba(base)[0]
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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--league", default="E0")
    ap.add_argument("--seasons", default="2223,2324,2425")
    ap.add_argument("--home")
    ap.add_argument("--away")
    ap.add_argument("--no-history", action="store_true")
    args = ap.parse_args()

    print(f"Tabular libraries available: {sorted(TABULAR_AVAILABLE)}")
    df = load_table(args.league, args.seasons.split(","))
    print(f"Loaded {len(df)} matches ({args.league})")

    df, base, models = walk_forward_probs(df, int(len(df) * 0.6))
    report, stack, stack_no_market, tab = backtest(df, base)
    print(json.dumps(report, indent=2))

    if args.home and args.away:
        payload = demo_prediction(df, models, stack_no_market, tab,
                                  args.home, args.away, args.league)
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
