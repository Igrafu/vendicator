"""Cards, fouls and corners markets - thresholds chosen by the data.

Football-data.co.uk carries HY/AY (yellows), HR/AR (reds), HF/AF (fouls) and
HC/AC (corners) for every match. We learn each team's rate for and against,
combine them into a match expectation, and price 'N or more' lines with a
Poisson tail.

The lines are NOT hard-coded: `sensible_lines()` picks thresholds around the
match expectation, so a fixture expected to produce ~4 yellows offers 2+/3+/
4+/5+, while a corner market expected at ~10 offers 8+/9+/10+/11+. That keeps
every selection meaningful instead of showing dead 1+ lines at 99%.
"""
import math

from scipy.stats import poisson

MARKETS = (
    ("yellow_cards", "Yellow Cards", ("HY", "AY")),
    ("fouls", "Fouls", ("HF", "AF")),
    ("corners", "Corners", ("HC", "AC")),
)
FORM_N = 12          # matches of history per team
PRIOR_W = 6.0        # league-average prior weight (pseudo-matches)
LIVE_BAND = (4.0, 94.0)   # probabilities worth offering as a selection


def _f(v):
    try:
        return float(v)
    except (TypeError, ValueError):
        return None


def team_rates(rows):
    """rows: football-data.co.uk dicts (chronological).
    -> {market: {'league': avg_per_team, 'for': {team: rate},
                 'against': {team: rate}}}"""
    out = {}
    for key, _label, (hcol, acol) in MARKETS:
        totals, counts = {}, {}
        conceded = {}
        league_total, league_n = 0.0, 0
        for r in rows:
            h, a = r.get("HomeTeam"), r.get("AwayTeam")
            hv, av = _f(r.get(hcol)), _f(r.get(acol))
            if not h or not a or hv is None or av is None:
                continue
            for team, own, opp in ((h, hv, av), (a, av, hv)):
                totals[team] = totals.get(team, 0.0) + own
                conceded[team] = conceded.get(team, 0.0) + opp
                counts[team] = counts.get(team, 0) + 1
            league_total += hv + av
            league_n += 2
        if not league_n:
            continue
        league_avg = league_total / league_n
        out[key] = {
            "league": league_avg,
            "for": {t: (totals[t] + league_avg * PRIOR_W)
                    / (counts[t] + PRIOR_W) for t in totals},
            "against": {t: (conceded[t] + league_avg * PRIOR_W)
                        / (counts[t] + PRIOR_W) for t in conceded},
        }
    return out


def recent_rates(rows, n=FORM_N):
    """Same as team_rates but weighted to each team's last n matches -
    'especially with the form of said current team'."""
    per_team = {}
    for r in rows:
        for side in ("HomeTeam", "AwayTeam"):
            per_team.setdefault(r.get(side), []).append(r)
    trimmed = []
    seen = set()
    for team, matches in per_team.items():
        for r in matches[-n:]:
            key = id(r)
            if key not in seen:
                seen.add(key)
                trimmed.append(r)
    return team_rates(trimmed or rows)


def sensible_lines(expected, spread=None):
    """Thresholds worth offering around the expectation.

    The window widens with the size of the market: a yellow-card market
    expected at ~4 only supports a handful of live lines, while a fouls
    market expected at ~22 is still meaningful several either side. Scaling
    by the Poisson standard deviation keeps every offered line inside the
    range the fixture can plausibly reach.
    """
    if spread is None:
        spread = max(2, int(round(math.sqrt(max(expected, 1.0)) * 1.6)))
    lo = max(1, int(math.floor(expected)) - spread)
    hi = int(math.ceil(expected)) + spread
    return list(range(lo, hi + 1))


def market_table(rates, home, away):
    """-> [{key, label, expected, lines: [{line, label, pct}]}] for one match."""
    out = []
    for key, label, _cols in MARKETS:
        r = rates.get(key)
        if not r:
            continue
        lg = r["league"] or 1.0
        h_for = r["for"].get(home, lg)
        a_for = r["for"].get(away, lg)
        h_against = r["against"].get(home, lg)
        a_against = r["against"].get(away, lg)
        # a team's output is tempered by what the opponent tends to induce
        home_exp = (h_for + a_against) / 2
        away_exp = (a_for + h_against) / 2
        expected = home_exp + away_exp
        lines = []
        for line in sensible_lines(expected):
            over = float(poisson.sf(line - 1, expected)) * 100
            # a 98.9% line is not a selection, it is decoration - only
            # thresholds that are genuinely live for this fixture are offered
            if LIVE_BAND[0] <= over <= LIVE_BAND[1]:
                lines.append({"line": line, "label": f"{line}+", "side": "over",
                              "pct": round(over, 1)})
            # the matching "under" so the builder can be played either way
            if LIVE_BAND[0] <= 100 - over <= LIVE_BAND[1]:
                lines.append({"line": line, "label": f"under {line}",
                              "side": "under", "pct": round(100 - over, 1)})
        lines.sort(key=lambda ln: (ln["line"], ln["side"]))
        out.append({
            "key": key, "label": label,
            "expected": round(expected, 1),
            "home_expected": round(home_exp, 1),
            "away_expected": round(away_exp, 1),
            "lines": lines,
        })
    return out
