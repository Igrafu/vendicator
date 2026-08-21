"""Value engine: model probability vs market price -> EV > Edge > CLV."""
import math


def devig_proportional(odds):
    """odds: {outcome: decimal_odds} -> fair probabilities (margin stripped)."""
    implied = {k: 1.0 / v for k, v in odds.items()}
    total = sum(implied.values())
    return {k: p / total for k, p in implied.items()}


def devig_shin(odds, tol=1e-10):
    """Shin (1993) de-vig - accounts for insider trading, better for
    favourite-longshot bias than proportional."""
    implied = {k: 1.0 / v for k, v in odds.items()}
    total = sum(implied.values())
    z_lo, z_hi = 0.0, 0.5
    for _ in range(100):
        z = (z_lo + z_hi) / 2
        probs = {k: (math.sqrt(z * z + 4 * (1 - z) * (p * p) / total) - z)
                 / (2 * (1 - z)) for k, p in implied.items()}
        s = sum(probs.values())
        if abs(s - 1.0) < tol:
            break
        z_lo, z_hi = (z, z_hi) if s > 1.0 else (z_lo, z)
    return {k: p / s for k, p in probs.items()}


def expected_value(model_prob, decimal_odds, stake=1.0):
    """EV of a flat stake at the offered price."""
    return stake * (model_prob * (decimal_odds - 1) - (1 - model_prob))


def edge(model_prob, market_prob):
    """Percentage-point edge of the model over the de-vigged market."""
    return model_prob - market_prob


def clv(taken_odds, closing_odds, closing_fair_prob=None):
    """Closing Line Value: positive = beat the close (skill signal).
    If a de-vigged closing probability is given, use it; else raw close."""
    close = 1.0 / closing_fair_prob if closing_fair_prob else closing_odds
    return taken_odds / close - 1.0


def bookmaker_suggestion(model_probs, book_odds, min_edge=0.03):
    """Rank outcomes where model prob exceeds de-vigged market prob by
    min_edge. Informational only - the site never takes bets."""
    market = devig_shin(book_odds)
    picks = []
    for outcome, p in model_probs.items():
        if outcome not in book_odds:
            continue
        e = edge(p, market[outcome])
        if e >= min_edge:
            picks.append({
                "outcome": outcome,
                "model_prob": p,
                "market_prob": market[outcome],
                "edge": e,
                "offered_odds": book_odds[outcome],
                "fair_odds": 1.0 / p,
                "ev_per_unit": expected_value(p, book_odds[outcome]),
            })
    return sorted(picks, key=lambda x: x["ev_per_unit"], reverse=True)
