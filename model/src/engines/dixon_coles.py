"""Dixon-Coles (1997) time-decayed Poisson model.

The core output is a full scoreline probability grid, which is exactly what
the site displays: every market derives from it and every scoreline gets a
percentage, e.g. 2-1 = 17.3%.
"""
import math

import numpy as np
from scipy.optimize import minimize
from scipy.stats import poisson

# The grid runs deep enough that the long-shot scorelines a builder wants
# to reach - 8-6, 10-0, and beyond - are actually on it. Cells this far out
# carry vanishing probability, which is the point: they price as the wild
# calls they are.
MAX_GOALS = 15


def _tau(hg, ag, lam, mu, rho):
    """Low-score dependence correction."""
    if hg == 0 and ag == 0:
        return 1 - lam * mu * rho
    if hg == 0 and ag == 1:
        return 1 + lam * rho
    if hg == 1 and ag == 0:
        return 1 + mu * rho
    if hg == 1 and ag == 1:
        return 1 - rho
    return 1.0


class DixonColes:
    def __init__(self, decay_xi=0.0018):  # ~half-life of one year in days
        self.decay_xi = decay_xi
        self.teams = []
        self.params = None  # attack[n], defence[n], home_adv, rho

    def fit(self, matches):
        """matches: list of dicts {home, away, hg, ag, days_ago}."""
        self.teams = sorted({m["home"] for m in matches} |
                            {m["away"] for m in matches})
        idx = {t: i for i, t in enumerate(self.teams)}
        n = len(self.teams)
        x0 = np.concatenate([np.zeros(n), np.zeros(n), [0.25], [-0.05]])

        weights = np.array([math.exp(-self.decay_xi * m["days_ago"])
                            for m in matches])
        h = np.array([idx[m["home"]] for m in matches])
        a = np.array([idx[m["away"]] for m in matches])
        hg = np.array([m["hg"] for m in matches])
        ag = np.array([m["ag"] for m in matches])

        def nll(p):
            atk, dfn = p[:n], p[n:2 * n]
            gamma, rho = p[2 * n], p[2 * n + 1]
            lam = np.exp(atk[h] + dfn[a] + gamma)
            mu = np.exp(atk[a] + dfn[h])
            ll = (poisson.logpmf(hg, lam) + poisson.logpmf(ag, mu))
            tau = np.array([_tau(g1, g2, l, m_, rho) for g1, g2, l, m_
                            in zip(hg, ag, lam, mu)])
            ll += np.log(np.clip(tau, 1e-10, None))
            # identifiability: attacks sum to 0
            return -(weights * ll).sum() + 1000 * atk.sum() ** 2

        res = minimize(nll, x0, method="L-BFGS-B",
                       options={"maxiter": 200})
        self.params = res.x
        return self

    def score_grid(self, home, away):
        """Return (grid, lam, mu): grid[i][j] = P(home scores i, away j)."""
        n = len(self.teams)
        idx = {t: i for i, t in enumerate(self.teams)}
        atk, dfn = self.params[:n], self.params[n:2 * n]
        gamma, rho = self.params[2 * n], self.params[2 * n + 1]
        lam = math.exp(atk[idx[home]] + dfn[idx[away]] + gamma)
        mu = math.exp(atk[idx[away]] + dfn[idx[home]])
        grid = np.outer(poisson.pmf(range(MAX_GOALS + 1), lam),
                        poisson.pmf(range(MAX_GOALS + 1), mu))
        for i in (0, 1):
            for j in (0, 1):
                grid[i, j] *= _tau(i, j, lam, mu, rho)
        grid /= grid.sum()
        return grid, lam, mu


TOTAL_LINES = (0.5, 1.5, 2.5, 3.5, 4.5, 5.5, 6.5, 7.5)

# Share of a match's goals scored in the first half. Football is reliably
# back-loaded (fresher legs, fewer risks taken early, chasing games late);
# the long-run split across the top European leagues sits near 45/55, which
# is what the half-time markets below are built on.
FIRST_HALF_SHARE = 0.45


def markets_from_grid(grid):
    """Derive every displayed market, as probabilities, from a scoreline grid."""
    home = float(np.tril(grid, -1).sum())   # i > j
    away = float(np.triu(grid, 1).sum())    # j > i
    draw = float(np.trace(grid))
    totals = {}
    for line in TOTAL_LINES:
        over = sum(grid[i, j] for i in range(grid.shape[0])
                   for j in range(grid.shape[1]) if i + j > line)
        totals[f"over_{line}"] = float(over)
        totals[f"under_{line}"] = float(1 - over)
    btts_yes = float(grid[1:, 1:].sum())
    ranked = sorted(
        ((f"{i}-{j}", float(grid[i, j]))
         for i in range(grid.shape[0]) for j in range(grid.shape[1])),
        key=lambda kv: kv[1], reverse=True)
    # a clean sheet is simply the opponent failing to score
    home_cs = float(grid[:, 0].sum())
    away_cs = float(grid[0, :].sum())
    return {
        "1x2": {"home": home, "draw": draw, "away": away},
        "double_chance": {"1x": home + draw, "12": home + away,
                          "x2": draw + away},
        "btts": {"yes": btts_yes, "no": 1 - btts_yes},
        "clean_sheet": {"home": home_cs, "away": away_cs},
        "totals": totals,
        "exact_score_top10": ranked[:10],
        # The full board, not a shortlist. Anything the model gives at least
        # a one-in-fifty-thousand chance is offered, which reaches 8-6 and
        # 10-0 while stopping short of scorelines that are arithmetically
        # possible but meaningless.
        "exact_score_board": [kv for kv in ranked if kv[1] >= 0.00002],
    }


def _half_1x2(lam, mu):
    """1X2 for one half from that half's expected goals."""
    n = 8
    h = poisson.pmf(range(n), lam)
    a = poisson.pmf(range(n), mu)
    g = np.outer(h, a)
    g /= g.sum()
    return {"home": float(np.tril(g, -1).sum()),
            "draw": float(np.trace(g)),
            "away": float(np.triu(g, 1).sum())}


def half_markets(lam, mu):
    """First-half and second-half result markets, plus HT/FT combinations.

    The two halves are priced independently off their own expected goals
    (`FIRST_HALF_SHARE` of the match total in the first, the rest in the
    second), which is why a second-half home win prices differently from a
    first-half one even for the same fixture.
    """
    s = FIRST_HALF_SHARE
    first = _half_1x2(lam * s, mu * s)
    second = _half_1x2(lam * (1 - s), mu * (1 - s))
    combos = {}
    for hk, hv in first.items():
        for fk, fv in second.items():
            combos[f"{hk}_{fk}"] = float(hv * fv)
    return {"first_half": first, "second_half": second,
            "ht_ft": combos}


def as_percentages(obj, dp=1):
    """Recursively convert probabilities to display percentages (2-1 = 17.3)."""
    if isinstance(obj, dict):
        return {k: as_percentages(v, dp) for k, v in obj.items()}
    if isinstance(obj, (list, tuple)):
        return [as_percentages(v, dp) for v in obj]
    if isinstance(obj, float):
        return round(obj * 100, dp)
    return obj
