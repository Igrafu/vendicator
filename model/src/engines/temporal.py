"""MODEL B - live in-play prediction.

Two layers:

1. `InPlayEngine` (implemented, runs today): analytic conditional model.
   Given the pre-match Dixon-Coles rates and the CURRENT match state
   (minute, score, red cards), it recomputes the remaining-time scoreline
   distribution -> live win probability, next-goal probability, live
   over/under, expected final score, momentum-adjusted rates. This is what
   free-tier polling data (score/cards/subs) can drive right now.

2. `TemporalTransformerStub` (Phase 2): a small temporal Transformer over
   structured match-state sequences - NOT a giant generic transformer. Each
   timestep is a structured vector (minute, score diff, cards, subs left,
   shot/xG momentum window, phase flags). Training needs event-stream data
   (Sportmonks/StatsBomb tier), so this stays an interface until that data
   is purchased.
"""
import numpy as np
from scipy.stats import poisson

MAX_G = 8
RED_CARD_ATK = 0.7   # attacking rate multiplier when a side is down a man
RED_CARD_DEF = 1.15  # opponent's rate multiplier


class MatchState:
    def __init__(self, minute, home_goals, away_goals,
                 home_reds=0, away_reds=0, momentum=0.0):
        """momentum: -1..+1, rolling shot/attack share (home positive).
        With free-tier data leave 0; event feeds populate it later."""
        self.minute = minute
        self.home_goals = home_goals
        self.away_goals = away_goals
        self.home_reds = home_reds
        self.away_reds = away_reds
        self.momentum = momentum


class InPlayEngine:
    def __init__(self, lam, mu, total_minutes=93.0):
        self.lam = lam    # pre-match home goal expectation (Dixon-Coles)
        self.mu = mu
        self.total = total_minutes

    def _remaining_rates(self, state):
        frac = max(self.total - state.minute, 0.0) / self.total
        lam, mu = self.lam * frac, self.mu * frac
        for _ in range(state.home_reds):
            lam *= RED_CARD_ATK
            mu *= RED_CARD_DEF
        for _ in range(state.away_reds):
            mu *= RED_CARD_ATK
            lam *= RED_CARD_DEF
        boost = 1.0 + 0.25 * state.momentum
        return lam * boost, mu / max(boost, 1e-6)

    def live_probs(self, state):
        lam, mu = self._remaining_rates(state)
        grid = np.outer(poisson.pmf(range(MAX_G + 1), lam),
                        poisson.pmf(range(MAX_G + 1), mu))
        grid /= grid.sum()

        p_home = p_draw = p_away = 0.0
        exp_h = state.home_goals + lam
        exp_a = state.away_goals + mu
        over_lines = {1.5: 0.0, 2.5: 0.0, 3.5: 0.0}
        for i in range(MAX_G + 1):
            for j in range(MAX_G + 1):
                fh, fa = state.home_goals + i, state.away_goals + j
                p = grid[i, j]
                if fh > fa:
                    p_home += p
                elif fh == fa:
                    p_draw += p
                else:
                    p_away += p
                for line in over_lines:
                    if fh + fa > line:
                        over_lines[line] += p

        p_any_goal = 1.0 - grid[0, 0]
        next_goal_home = (lam / (lam + mu) * p_any_goal) if lam + mu > 0 else 0
        return {
            "minute": state.minute,
            "score": f"{state.home_goals}-{state.away_goals}",
            "win_prob": {"home": p_home, "draw": p_draw, "away": p_away},
            "next_goal": {"home": next_goal_home,
                          "away": p_any_goal - next_goal_home,
                          "none": grid[0, 0]},
            "over_under": {f"over_{k}": v for k, v in over_lines.items()},
            "expected_final_score": f"{exp_h:.1f}-{exp_a:.1f}",
            "momentum": state.momentum,
        }


class TemporalPrototype:
    """GBM temporal model trained on StatsBomb Open Data snapshots
    (train_temporal_prototype.py). Free stand-in for the Transformer; loads
    from model/output/temporal_prototype.pkl when present. Admin-selectable
    next to InPlayEngine; the Transformer replaces it in Phase 2."""

    def __init__(self, path=None):
        import pickle
        from pathlib import Path
        path = path or (Path(__file__).resolve().parents[3] / "model"
                        / "output" / "temporal_prototype.pkl")
        with open(path, "rb") as f:
            bundle = pickle.load(f)
        self.model = bundle["model"]
        self.features = bundle["features"]
        self.classes = bundle["classes"]

    def live_probs(self, state, shots_h_10=0, shots_a_10=0, xg_h_10=0.0,
                   xg_a_10=0.0, xg_h_total=0.0, xg_a_total=0.0):
        row = {"minute_frac": state.minute / 93.0,
               "goal_diff": state.home_goals - state.away_goals,
               "total_goals": state.home_goals + state.away_goals,
               "home_reds": state.home_reds, "away_reds": state.away_reds,
               "shots_h_10": shots_h_10, "shots_a_10": shots_a_10,
               "xg_h_10": xg_h_10, "xg_a_10": xg_a_10,
               "xg_h_total": xg_h_total, "xg_a_total": xg_a_total}
        X = np.array([[row[f] for f in self.features]])
        p = self.model.predict_proba(X)[0]
        return {"minute": state.minute,
                "score": f"{state.home_goals}-{state.away_goals}",
                "win_prob": dict(zip(("home", "draw", "away"), map(float, p)))}


class TemporalTransformerStub:
    """Phase 2 interface. Timestep vector (structured, not raw text/video):
    [minute/90, goal_diff, home_reds, away_reds, subs_used_h, subs_used_a,
     shots_last10_h, shots_last10_a, xg_last10_h, xg_last10_a,
     is_set_piece_phase, score_state_onehot...]
    Trains on event streams to predict the same outputs as InPlayEngine,
    replacing it when it beats the analytic model on live Brier score."""

    REQUIRED_DATA = "event-level feeds (Sportmonks advanced / StatsBomb)"

    def live_probs(self, state):
        raise NotImplementedError(
            f"Temporal transformer needs {self.REQUIRED_DATA}; "
            "use InPlayEngine until then.")
