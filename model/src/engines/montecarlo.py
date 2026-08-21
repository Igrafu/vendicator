"""Opta-Supercomputer-style Monte Carlo: simulate the remaining season from
per-match scoreline grids -> league position / title / relegation
probabilities as percentages."""
import numpy as np


def simulate_league(current_points, remaining_fixtures, grid_fn,
                    n_sims=10_000, seed=42):
    """current_points: {team: pts}
    remaining_fixtures: [(home, away), ...]
    grid_fn: callable (home, away) -> scoreline grid (from DixonColes)
    Returns {team: {"title": p, "top4": p, "relegation": p, "avg_pos": x}}."""
    rng = np.random.default_rng(seed)
    teams = sorted(current_points)
    t_idx = {t: i for i, t in enumerate(teams)}
    n = len(teams)

    # Precompute outcome probabilities per fixture
    fixture_probs = []
    for home, away in remaining_fixtures:
        grid = grid_fn(home, away)[0] if isinstance(grid_fn(home, away), tuple) \
            else grid_fn(home, away)
        p_home = np.tril(grid, -1).sum()
        p_draw = np.trace(grid)
        p_away = np.triu(grid, 1).sum()
        fixture_probs.append((t_idx[home], t_idx[away],
                              [p_home, p_draw, p_away]))

    base = np.array([current_points[t] for t in teams], dtype=float)
    finishes = np.zeros((n, n))  # finishes[team, position]

    for _ in range(n_sims):
        pts = base.copy()
        for hi, ai, probs in fixture_probs:
            outcome = rng.choice(3, p=np.array(probs) / sum(probs))
            if outcome == 0:
                pts[hi] += 3
            elif outcome == 1:
                pts[hi] += 1
                pts[ai] += 1
            else:
                pts[ai] += 3
        order = np.argsort(-(pts + rng.random(n) * 1e-6))  # random tiebreak
        for pos, ti in enumerate(order):
            finishes[ti, pos] += 1

    finishes /= n_sims
    return {
        t: {
            "title": float(finishes[i, 0]),
            "top4": float(finishes[i, :4].sum()),
            "relegation": float(finishes[i, -3:].sum()),
            "avg_pos": float((finishes[i] * np.arange(1, n + 1)).sum()),
        }
        for t, i in t_idx.items()
    }
