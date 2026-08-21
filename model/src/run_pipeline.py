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
from engines.dixon_coles import half_markets
from engines.graph import TacticalReport
from discipline import market_table, recent_rates
from scoreline import build as scoreline_build
from features import FEATURE_COLS, build_match_table, normalise_understat
from players import team_players, team_points
from teams import (as_dict as team_registry, canonical, code as team_code,
                   country as team_country)
from llm_explain import explanation_context

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / "model" / "output" / "predictions.json"
HISTORY = ROOT / "records" / "model-history.jsonl"
UNIFORM = np.array([1 / 3, 1 / 3, 1 / 3])

FootballDataCoUk.CACHE = ROOT / "model" / "data"

UNDERSTAT_LEAGUE = {"E0": "EPL", "SP1": "La_liga", "D1": "Bundesliga",
                    "I1": "Serie_A", "F1": "Ligue_1"}
ALL_SEASONS = "1617,1718,1819,1920,2021,2122,2223,2324,2425,2526"
DEFAULT_SEASONS = {d: ALL_SEASONS for d in
                   ("E0", "E1", "E2", "E3", "SP1", "SP2", "I1", "I2",
                    "D1", "F1")}

UNDERSTAT_SEASON = "2024"


def short_code(team):
    return team_code(team)


def _table_rows(div, season):
    """Standings for one division and season, from the free results CSV."""
    import csv as _csv
    import io as _io
    import requests
    r = requests.get(
        f"https://www.football-data.co.uk/mmz4281/{season}/{div}.csv",
        timeout=60,
        # standings are never served from a stale copy
        headers={"User-Agent": "VendicatorModel/0.1",
                 "Cache-Control": "no-cache"})
    r.raise_for_status()
    table = {}
    for row in _csv.DictReader(_io.StringIO(
            r.content.decode("utf-8-sig", errors="ignore"))):
        try:
            hg, ag = int(row["FTHG"]), int(row["FTAG"])
        except (KeyError, ValueError):
            continue
        for team, gf, ga in ((canonical(row["HomeTeam"]), hg, ag),
                             (canonical(row["AwayTeam"]), ag, hg)):
            e = table.setdefault(team, {"team": team, "p": 0, "w": 0, "d": 0,
                                        "l": 0, "gf": 0, "ga": 0, "pts": 0})
            e["p"] += 1
            e["gf"] += gf
            e["ga"] += ga
            if gf > ga:
                e["w"] += 1
                e["pts"] += 3
            elif gf == ga:
                e["d"] += 1
                e["pts"] += 1
            else:
                e["l"] += 1
    return sorted(table.values(),
                  key=lambda e: (-e["pts"], -(e["gf"] - e["ga"]), -e["gf"]))


# which season each division's standings were actually read from, so the
# site can say so rather than labelling a finished table "live"
TABLE_SEASONS = {}


def league_table(div, season=None):
    """Live standings for the season actually in progress.

    The season code is derived, never hard-coded - a fixed code silently
    serves last season's table once the calendar rolls over in July. Very
    early in a new season the current CSV can exist but be empty, so the
    previous season is used until real results land.
    """
    from season_log import current_season_code
    if season:
        TABLE_SEASONS[div] = {"code": season, "current": True}
        return _table_rows(div, season)
    code = current_season_code()
    try:
        rows = _table_rows(div, code)
    except Exception:
        rows = []
    if rows:
        TABLE_SEASONS[div] = {"code": code, "current": True}
        return rows
    prev = f"{(int(code[:2]) - 1) % 100:02d}{int(code[:2]) % 100:02d}"
    print(f"  table {div}: {code} has no results yet, showing {prev}")
    TABLE_SEASONS[div] = {"code": prev, "current": False}
    return _table_rows(div, prev)


ODDS_BOOKS = [("Bet365", "B365"), ("Betfair", "BFD"), ("BetVictor", "BV"),
              ("Betway", "BW"), ("Paddy Power", "PP"), ("SkyBet", "SKB"),
              ("Betfair Exchange", "BFE"), ("Market Max", "Max")]

# fixtures.csv name variants -> historical results-CSV names
FIXTURES_ALIASES = {
    "Atl. Madrid": "Ath Madrid", "Atl. Bilbao": "Ath Bilbao",
    "Espanyol": "Espanol", "Real Sociedad": "Sociedad",
    "Real Betis": "Betis", "Rayo Vallecano": "Vallecano",
    "Celta Vigo": "Celta", "Deportivo Alaves": "Alaves",
    "Bradford City": "Bradford", "Sheffield Wed": "Sheffield Weds",
    "Nottm Forest": "Nott'm Forest", "AC Milan": "Milan",
    "Paris Saint-Germain": "Paris SG", "Inter Milan": "Inter",
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


def attach_gameweeks(payload):
    """Stamp each fixture with its competition gameweek + season label,
    read from the season log."""
    log = ROOT / "records" / "season-log.jsonl"
    weeks = {}
    if log.exists():
        for line in log.read_text().splitlines():
            if not line.strip():
                continue
            m = json.loads(line)
            weeks[(m["competition"], m["home"], m["away"])] = (
                m.get("gameweek"), m.get("season"))
    from season_log import current_season_code, season_label
    code = current_season_code()
    for fx in payload.get("fixtures", []):
        gw, season = weeks.get(
            (fx["league"], fx.get("home_team"), fx.get("away_team")),
            (None, code))
        fx["gameweek"] = gw
        fx["season"] = season
        fx["season_label"] = season_label(season)
    return payload


def fetch_upcoming():
    """Free upcoming fixtures + current bookmaker odds for every
    football-data.co.uk division (updates continuously)."""
    import requests
    r = requests.get("https://www.football-data.co.uk/fixtures.csv",
                     timeout=30)
    r.raise_for_status()
    import csv as _csv
    import io as _io
    return list(_csv.DictReader(_io.StringIO(
        r.content.decode("utf-8-sig", errors="ignore"))))


def push_to_wp(payload, route="predictions"):
    import os
    import requests
    from dotenv import load_dotenv
    load_dotenv(ROOT / ".env")
    base = os.getenv("WP_BASE_URL", "").rstrip("/")
    auth = (os.getenv("WP_APP_USER", ""), os.getenv("WP_APP_PASSWORD", ""))
    r = requests.post(f"{base}/wp-json/vendicator/v1/{route}", json=payload,
                      auth=auth, timeout=40,
                      headers={"User-Agent": "Mozilla/5.0 (VendicatorBot)"})
    print(f"WP push {route}: {r.status_code} {r.text[:80]}")
    return r.ok


def kickoff_epoch(date_str, time_str):
    """'21/08/2026' + '15:00' -> UTC epoch seconds.

    football-data.co.uk publishes kick-offs in UK local time. The site needs
    one absolute instant so every clock, countdown and 'has this started yet'
    check agrees regardless of where the member is reading from.
    """
    from zoneinfo import ZoneInfo
    for fmt in ("%d/%m/%Y", "%d/%m/%y"):
        try:
            d = datetime.strptime(date_str.strip(), fmt)
            break
        except (ValueError, AttributeError):
            d = None
    if d is None:
        return None
    hh, mm = 15, 0
    if time_str and ":" in str(time_str):
        try:
            hh, mm = (int(x) for x in str(time_str).split(":")[:2])
        except ValueError:
            pass
    local = d.replace(hour=hh, minute=mm, tzinfo=ZoneInfo("Europe/London"))
    return int(local.astimezone(timezone.utc).timestamp())


def fixture_extras(home, away, league, raw_rows, table):
    """Discipline markets + player markets + team-pick point values."""
    out = {}
    try:
        out["discipline"] = market_table(recent_rates(raw_rows), home, away)
    except Exception as e:
        print(f"  discipline unavailable: {str(e)[:60]}")
    us = UNDERSTAT_LEAGUE.get(league)
    if us:
        players = []
        for team in (home, away):
            try:
                players += team_players(team, us, UNDERSTAT_SEASON)
            except Exception as e:
                print(f"  players {team}: {str(e)[:60]}")
        if players:
            out["players"] = players
    rows = {r["team"]: r for r in table or []}
    out["team_points"] = {
        canonical(home): team_points(rows.get(canonical(home))),
        canonical(away): team_points(rows.get(canonical(away))),
    }
    return out


def fixture_payload(models, stack, stack_nm, tab, draw_head, df, fx, league):
    """Full percentage payload for one upcoming fixture, using bookmaker
    odds as a model input when present."""
    from engines.value import devig_proportional
    home, away = fx["HomeTeam"], fx["AwayTeam"]
    dc, elo, bayes = models["dc"], models["elo"], models["bayes"]
    grid, lam, mu = dc.score_grid(home, away)
    markets = markets_from_grid(grid)
    ep = elo.probs(home, away)
    bp = bayes.probs_with_uncertainty(home, away)
    feat_row = np.full((1, len([c for c in FEATURE_COLS
                                if c in df.columns])), np.nan)
    base = {"elo": np.array([[ep["home"], ep["draw"], ep["away"]]]),
            "dixon_coles": np.array([[markets["1x2"]["home"],
                                      markets["1x2"]["draw"],
                                      markets["1x2"]["away"]]]),
            "bayesian": np.array([[bp["home"], bp["draw"], bp["away"]]]),
            "xg_poisson": UNIFORM.reshape(1, 3),
            "tabular": tab.predict_proba(feat_row)}
    p_draw = draw_head.predict_proba(np.nan_to_num(feat_row))[:, 1]
    odds = {}
    for k, col in (("home", "B365H"), ("draw", "B365D"), ("away", "B365A")):
        try:
            v = float(fx.get(col) or fx.get("Avg" + col[-1]) or 0)
            if v > 1:
                odds[k] = v
        except ValueError:
            pass
    if len(odds) == 3:
        fair = devig_proportional(odds)
        base["market"] = np.array([[fair["home"], fair["draw"],
                                    fair["away"]]])
        final = stack.predict_proba(base, context=p_draw.reshape(-1, 1))[0]
    else:
        final = stack_nm.predict_proba(base,
                                       context=p_draw.reshape(-1, 1))[0]
    p_home_scores = float(1 - grid[0, :].sum())
    p_away_scores = float(1 - grid[:, 0].sum())
    best_side = "home" if p_home_scores >= p_away_scores else "away"
    odds_board = {}
    for okey, suffix in (("home", "H"), ("draw", "D"), ("away", "A")):
        prices = []
        for label, prefix in ODDS_BOOKS:
            try:
                v = float(fx.get(prefix + suffix) or 0)
                if v > 1:
                    prices.append({"book": label, "odds": v})
            except (TypeError, ValueError):
                pass
        odds_board[okey] = sorted(prices,
                                  key=lambda x: -x["odds"])[:3]
    ch, ca = canonical(home), canonical(away)
    # VScore: the engine's single best scoreline call. The most likely cell
    # of the Dixon-Coles grid, reported with how far clear of the runner-up
    # it is, so a 2-1 the model barely prefers is not passed off as a 2-1 it
    # is confident about.
    board = markets["exact_score_board"]
    top, second = board[0], (board[1] if len(board) > 1 else (None, 0.0))
    vs_home, vs_away = (int(x) for x in top[0].split("-"))
    edge = (top[1] - second[1]) * 100
    vscore = {
        "home": vs_home, "away": vs_away, "score": top[0],
        "pct": round(top[1] * 100, 1),
        "edge": round(edge, 1),
        "confidence": ("high" if edge >= 3.0 else
                       "medium" if edge >= 1.0 else "low"),
        "runner_up": second[0],
    }
    return {
        "league": league,
        "kickoff": f"{fx.get('Date', '')} {fx.get('Time', '')}".strip(),
        "kickoff_ts": kickoff_epoch(fx.get("Date", ""), fx.get("Time", "")),
        "fixture": f"{ch} vs {ca}",
        "home_team": ch, "away_team": ca,
        "country": team_country(ch),
        "short": f"{short_code(ch)} Vs {short_code(ca)}",
        "team_to_score": {
            "home_pct": round(p_home_scores * 100, 1),
            "away_pct": round(p_away_scores * 100, 1),
            "best": best_side,
            "best_team": ch if best_side == "home" else ca,
            "fair_odds": {
                "home": round(1 / max(p_home_scores, 1e-6), 2),
                "away": round(1 / max(p_away_scores, 1e-6), 2)}},
        "odds_board": {k: v for k, v in odds_board.items() if v} or None,
        "expected_goals": {"home": round(lam, 2), "away": round(mu, 2)},
        "final_calibrated": as_percentages(
            {"home": float(final[0]), "draw": float(final[1]),
             "away": float(final[2])}),
        "markets_dixon_coles": as_percentages(markets),
        "markets_halves": as_percentages(half_markets(lam, mu)),
        "vscore": vscore,
        "uncertainty_band_home_pct": [round(x * 100, 1)
                                      for x in bp["home_ci90"]],
        "reward_difficulty_multiplier": bayes.difficulty_multiplier(home,
                                                                    away),
        "book_odds": odds or None,
    }


def predict_upcoming(push=True):
    fixtures = fetch_upcoming()
    by_div = {}
    for fx in fixtures:
        div = fx.get("Div")
        if div in DEFAULT_SEASONS:
            by_div.setdefault(div, []).append(fx)
    out_fixtures = []
    all_tables = {}
    for div, fxs in sorted(by_div.items()):
        print(f"\n=== upcoming: {div} ({len(fxs)} fixtures) ===")
        df, base, models, (report, stack, stack_nm, tab, draw_head) = \
            run_league(div)
        try:
            all_tables[div] = league_table(div)
        except Exception as e:
            print(f"  table {div}: {str(e)[:60]}")
            all_tables[div] = []
        raw_rows = []
        src = FootballDataCoUk()
        for s in DEFAULT_SEASONS.get(div, ALL_SEASONS).split(",")[-4:]:
            try:
                raw_rows += src.season_csv(s, div)
            except Exception:
                pass
        # refit team-strength models on ALL data (the backtest split holds
        # out recent matches, which would leave promoted teams unseen)
        today = df["date"].max()
        all_matches = [{"home": m["home"], "away": m["away"], "hg": m["hg"],
                        "ag": m["ag"], "days_ago": (today - m["date"]).days}
                       for _, m in df.iterrows()]
        models["dc"] = DixonColes().fit(all_matches)
        models["bayes"] = BayesianStrengths().fit(
            [(m["home"], m["away"], m["hg"], m["ag"])
             for m in all_matches])
        known = set(models["dc"].teams)
        for fx in fxs:
            fx["HomeTeam"] = FIXTURES_ALIASES.get(fx["HomeTeam"],
                                                  fx["HomeTeam"])
            fx["AwayTeam"] = FIXTURES_ALIASES.get(fx["AwayTeam"],
                                                  fx["AwayTeam"])
            if fx["HomeTeam"] not in known or fx["AwayTeam"] not in known:
                print(f"  skip {fx['HomeTeam']} vs {fx['AwayTeam']} "
                      "(team unseen in training window)")
                continue
            p = fixture_payload(models, stack, stack_nm, tab, draw_head,
                                df, fx, div)
            p.update(fixture_extras(fx["HomeTeam"], fx["AwayTeam"], div,
                                    raw_rows, all_tables.get(div)))
            out_fixtures.append(p)
            append_history(
                {"generated": datetime.now(timezone.utc).isoformat(),
                 "league": div, "fixture": p["fixture"],
                 "final_calibrated": p["final_calibrated"]}, div,
                kickoff=p["kickoff"],
                difficulty=p["reward_difficulty_multiplier"])
            print(f"  {p['fixture']}: {p['final_calibrated']}")
    from season_log import season_label
    payload = {"generated": datetime.now(timezone.utc).isoformat(),
               "fixtures": out_fixtures, "tables": all_tables,
               "table_seasons": {d: dict(v, label=season_label(v["code"]))
                                 for d, v in TABLE_SEASONS.items()},
               "teams": {c: v for c, v in team_registry().items()}}
    attach_gameweeks(payload)
    scoreline_build(payload)
    OUTPUT.write_text(json.dumps(payload, indent=2))
    print(f"\n{len(out_fixtures)} predictions -> {OUTPUT}")
    if push and out_fixtures:
        push_to_wp(payload)
    return payload


def append_history(payload, league, kickoff=None, difficulty=None):
    rec = {"ts": payload["generated"], "league": league,
           "home": payload["fixture"].split(" vs ")[0],
           "away": payload["fixture"].split(" vs ")[1],
           "kickoff": kickoff, "difficulty": difficulty,
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
    ap.add_argument("--upcoming", action="store_true",
                    help="predict all upcoming fixtures (fixtures.csv) and "
                         "push to the site")
    ap.add_argument("--no-push", action="store_true")
    args = ap.parse_args()

    print(f"Tabular libraries available: {sorted(TABULAR_AVAILABLE)}")
    if args.upcoming:
        predict_upcoming(push=not args.no_push)
        return
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
