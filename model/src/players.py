"""Player module - profiles, last-5 form and market-value ratings.

Free source: Understat (season aggregates via getLeagueData, per-match
history via getPlayerData). Everything is cached to model/data/players/ so
repeat runs cost nothing.

Two outputs the site consumes:
  * `player_market(team)` - the score / assist / score-or-assist table with
    a last-5 tally per player (goal, assist, blank, or did-not-play)
  * `player_profile(id)`  - rich profile: season stats, value rating, form

Player value rating (0-100) blends the signals the brief asked for:
  goals, assists, key passes (creation), shots, minutes played, per-90
  output and finishing quality (goals vs xG) - a model-derived stand-in for
  market value that needs no paid transfer feed. Points paid for a player
  pick scale INVERSELY with that rating: backing a low-value player to score
  pays more than backing a superstar.
"""
import json
import time
from pathlib import Path

import requests
from scipy.stats import poisson

from teams import canonical

ROOT = Path(__file__).resolve().parents[2]
CACHE = ROOT / "model" / "data" / "players"
BASE = "https://understat.com"
HEAD = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                      "AppleWebKit/537.36",
        "X-Requested-With": "XMLHttpRequest"}
TTL = 24 * 3600


def _cached(name, fetch, ttl=TTL):
    CACHE.mkdir(parents=True, exist_ok=True)
    f = CACHE / f"{name}.json"
    if f.exists() and time.time() - f.stat().st_mtime < ttl:
        return json.loads(f.read_text())
    data = fetch()
    f.write_text(json.dumps(data))
    return data


def league_players(league="EPL", season="2024"):
    """Season aggregates for every player in a league."""
    def fetch():
        r = requests.get(f"{BASE}/getLeagueData/{league}/{season}",
                         headers={**HEAD,
                                  "Referer": f"{BASE}/league/{league}/{season}"},
                         timeout=60)
        r.raise_for_status()
        return r.json().get("players", [])
    return _cached(f"league_{league}_{season}", fetch)


def player_matches(player_id):
    """Per-match history (most recent first)."""
    def fetch():
        r = requests.get(f"{BASE}/getPlayerData/{player_id}",
                         headers={**HEAD,
                                  "Referer": f"{BASE}/player/{player_id}"},
                         timeout=60)
        r.raise_for_status()
        return r.json().get("matches", [])
    return _cached(f"player_{player_id}", fetch)


def _f(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return default


def value_rating(p):
    """0-100 composite: output, creation, volume, efficiency, minutes."""
    mins = max(_f(p.get("time")), 1.0)
    per90 = 90.0 / mins
    goals, assists = _f(p.get("goals")), _f(p.get("assists"))
    xg, xa = _f(p.get("xG")), _f(p.get("xA"))
    shots, keyp = _f(p.get("shots")), _f(p.get("key_passes"))
    score = 0.0
    score += min(goals * per90 * 55, 34)          # scoring rate
    score += min(assists * per90 * 60, 22)        # creation rate
    score += min(keyp * per90 * 7, 14)            # chance creation
    score += min(shots * per90 * 4, 10)           # involvement
    score += min(mins / 3000 * 12, 12)            # trust / minutes
    if xg > 0.5:                                  # finishing quality
        score += max(min((goals - xg) * 4, 8), -4)
    if xa > 0.5:
        score += max(min((assists - xa) * 4, 5), -3)
    return round(max(min(score, 100.0), 1.0), 1)


DEF_WEIGHT = {"D": 2.6, "DM": 2.4, "M": 1.7, "AM": 1.1, "F": 0.7,
              "S": 0.7, "GK": 0.3}

# Understat writes positions as terse codes ("AMR", "DC", "F S"). The site
# shows the short group next to a name in the markets grid and the full
# description on the player's own profile.
POSITION_LONG = {
    "GK": ("GK", "Goalkeeper"),
    "D": ("DEF", "Defender"),
    "DC": ("DEF", "Centre-Back"),
    "DR": ("DEF", "Right-Back"),
    "DL": ("DEF", "Left-Back"),
    "DM": ("MID", "Defensive Midfielder"),
    "DMC": ("MID", "Central Defensive Midfielder"),
    "M": ("MID", "Midfielder"),
    "MC": ("MID", "Central Midfielder"),
    "MR": ("MID", "Right Midfielder"),
    "ML": ("MID", "Left Midfielder"),
    "AM": ("MID", "Attacking Midfielder"),
    "AMC": ("MID", "Attacking Midfielder (Centre)"),
    "AMR": ("FWD", "Right Winger"),
    "AML": ("FWD", "Left Winger"),
    "F": ("FWD", "Forward"),
    "FW": ("FWD", "Forward"),
    "FWR": ("FWD", "Right Forward"),
    "FWL": ("FWD", "Left Forward"),
    "S": ("FWD", "Striker"),
    "SUB": ("SUB", "Substitute"),
}


def position_detail(raw):
    """'AMR S' -> ('FWD', 'Right Winger, used as a substitute')."""
    tokens = [t.upper() for t in str(raw or "").replace(",", " ").split() if t]
    if not tokens:
        return "", "Position not recorded"
    # Understat appends S when a player has any substitute appearances; that
    # says nothing about where they play, so it is dropped rather than
    # reported as if it were their role.
    if len(tokens) > 1 and tokens[-1] == "S":
        tokens = tokens[:-1]
    named = [POSITION_LONG[t][1] for t in tokens if t in POSITION_LONG]
    group = next((POSITION_LONG[t][0] for t in tokens if t in POSITION_LONG), "")
    if not named:
        return group, str(raw)
    return group, " / ".join(dict.fromkeys(named))


# Probabilities worth printing as a selection. Anything longer than roughly
# 60/1 is not a market, it is a lottery ticket, and the points system is
# meant to be hard to farm rather than hard to believe.
LIVE_BAND = (1.6, 95.0)


def _tail(lmbda, k):
    """P(at least k) for a Poisson rate, as a percentage."""
    return round(float(poisson.sf(k - 1, max(lmbda, 1e-6))) * 100, 1)


def market_lines(p):
    """Per-market thresholds for one player, priced off their per-90 rate.

    Every market offers a ladder rather than a single yes/no, so a slip can
    be built on '2+ goals' or 'under 2 tackles' as well as the headline
    selection. Expected minutes are the player's own average, so a rotation
    option prices shorter than a nailed-on starter.
    """
    mins = max(_f(p.get("time")), 1.0)
    games = max(_f(p.get("games")), 1.0)
    share = min(mins / games, 90.0) / 90.0        # expected share of a match
    def rate(stat):
        return _f(p.get(stat)) / mins * 90.0 * share

    goals, assists = rate("goals"), rate("assists")
    keyp = rate("key_passes")
    tackles = _defensive_actions(p) / max(games, 1.0) * share
    yellow, red = rate("yellow_cards"), rate("red_cards")
    out = {
        "score": [{"line": k, "label": f"{k}+ goal" + ("s" if k > 1 else ""),
                   "pct": _tail(goals, k)} for k in (1, 2, 3, 4)],
        "assist": [{"line": k, "label": f"{k}+ assist" + ("s" if k > 1 else ""),
                    "pct": _tail(assists, k)} for k in (1, 2, 3)],
        "score_or_assist": [{"line": 1, "label": "Goal or assist",
                             "pct": _tail(goals + assists, 1)},
                            {"line": 2, "label": "2+ goals or assists",
                             "pct": _tail(goals + assists, 2)}],
        "key_passes": [{"line": k, "label": f"{k}+ key passes",
                        "pct": _tail(keyp, k)} for k in range(1, 8)],
        "tackles": [{"line": k, "label": f"{k}+ tackles",
                     "pct": _tail(tackles, k)} for k in range(1, 8)],
        "yellow_card": [{"line": 1, "label": "To be booked",
                         "pct": _tail(yellow, 1)},
                        {"line": 0, "label": "Not booked",
                         "pct": round(100 - _tail(yellow, 1), 1)}],
        "red_card": [{"line": 1, "label": "To be sent off",
                      "pct": max(_tail(red, 1), 0.4)}],
    }
    # Drop dead lines. The floor is deliberately not near-zero: a 0.3% line
    # is not a selection a bookmaker would print, and offering it only
    # invites members to farm the longest price on the board.
    return {m: [ln for ln in lines if LIVE_BAND[0] <= ln["pct"] <= LIVE_BAND[1]]
            or lines[:1] for m, lines in out.items()}


def _defensive_actions(p):
    """Estimated tackles+interceptions per season. Understat's open feed has
    no tackle counts, so this is a minutes-and-position model, surfaced on
    the site as an estimate rather than a recorded stat."""
    mins = max(_f(p.get("time")), 1.0)
    pos = (p.get("position") or "M").split()[0].upper()
    weight = DEF_WEIGHT.get(pos, 1.6)
    return int(round(mins / 90.0 * weight))


def market_points(rating, market):
    """Points for the extended player markets."""
    base = {"score": 120, "assist": 150, "score_or_assist": 90,
            "key_passes": 70, "tackles": 65,
            "yellow_card": 110, "red_card": 400}.get(market, 100)
    multiplier = 1.0 + (100.0 - rating) / 55.0
    return int(round(base * multiplier))


def points_for(rating, market):
    """Points a correct player pick pays. Inverse to value: a 90-rated
    striker scoring is expected, a 20-rated defender is not."""
    base = {"score": 120, "assist": 150, "score_or_assist": 90}.get(market, 100)
    multiplier = 1.0 + (100.0 - rating) / 55.0     # 1.0 (elite) .. ~2.8
    return int(round(base * multiplier))


def team_points(team_row, wins_logged=0):
    """Points a correct TEAM pick pays: scales with squad strength, record
    and how often the model's users have already cashed on that side."""
    if not team_row:
        return 100
    played = max(int(team_row.get("p", 0)), 1)
    ppg = int(team_row.get("pts", 0)) / played
    gd = int(team_row.get("gf", 0)) - int(team_row.get("ga", 0))
    strength = min(max(ppg / 3.0, 0.05), 1.0) * 0.7 + \
        min(max((gd / played + 2) / 4, 0), 1) * 0.3
    base = int(round(60 + (1.0 - strength) * 140))   # 60 (elite) .. 200
    return max(base - min(wins_logged * 2, 40), 40)


def last5_tally(matches, n=5):
    """Most recent n matches -> per-match status for goals and assists.
    'goal' / 'assist' / 'blank' / 'dnp' (did not play)."""
    goals, assists = [], []
    for m in matches[:n]:
        mins = _f(m.get("time"))
        if mins <= 0:
            goals.append("dnp")
            assists.append("dnp")
            continue
        goals.append("goal" if _f(m.get("goals")) > 0 else "blank")
        assists.append("assist" if _f(m.get("assists")) > 0 else "blank")
    while len(goals) < n:
        goals.append("dnp")
        assists.append("dnp")
    return {"goals": goals, "assists": assists}


def team_players(team, league="EPL", season="2024", limit=14):
    """Every regular for a team, ready for the market table.

    Understat carries goals, assists, key passes, shots and cards. Tackles
    are not in the open feed, so a defensive-work proxy is derived from
    position and minutes and is clearly labelled as such on the site.
    """
    canon = canonical(team)
    rows = [p for p in league_players(league, season)
            if canonical(p.get("team_title", "")) == canon]
    rows.sort(key=lambda p: (_f(p.get("goals")) + _f(p.get("assists")),
                             _f(p.get("time"))), reverse=True)
    out = []
    for p in rows[:limit]:
        rating = value_rating(p)
        try:
            tally = last5_tally(player_matches(p["id"]))
        except Exception:
            tally = {"goals": ["dnp"] * 5, "assists": ["dnp"] * 5}
        mins = max(_f(p.get("time")), 1.0)
        group, detail = position_detail(p.get("position"))
        out.append({
            "id": p.get("id"),
            "name": p.get("player_name"),
            "team": canon,
            "position": p.get("position", ""),
            "position_short": group,
            "position_long": detail,
            "games": int(_f(p.get("games"))),
            "minutes": int(mins),
            "goals": int(_f(p.get("goals"))),
            "assists": int(_f(p.get("assists"))),
            "xG": round(_f(p.get("xG")), 2),
            "xA": round(_f(p.get("xA")), 2),
            "shots": int(_f(p.get("shots"))),
            "key_passes": int(_f(p.get("key_passes"))),
            "yellow_cards": int(_f(p.get("yellow_cards"))),
            "red_cards": int(_f(p.get("red_cards"))),
            "tackles": _defensive_actions(p),
            "rating": rating,
            "last5": tally,
            "markets": market_lines(p),
            # points weight: low-rated players pay more for the same line
            "weight": round(1.0 + (100.0 - rating) / 110.0, 3),
            "points": {m: market_points(rating, m)
                       for m in ("score", "assist", "score_or_assist",
                                 "key_passes", "tackles",
                                 "yellow_card", "red_card")},
            # simple per-90 based likelihoods, shown as percentages
            "prob": {
                "score": round(min(_f(p.get("goals")) / mins * 90 * 100, 92), 1),
                "assist": round(min(_f(p.get("assists")) / mins * 90 * 100, 88), 1),
            },
        })
        time.sleep(0.2)
    return out


def player_profile(player_id, league="EPL", season="2024"):
    """Rich profile for the player page."""
    matches = player_matches(player_id)
    agg = None
    for p in league_players(league, season):
        if str(p.get("id")) == str(player_id):
            agg = p
            break
    recent = []
    for m in matches[:5]:
        recent.append({
            "date": m.get("date"),
            "fixture": f"{canonical(m.get('h_team'))} {m.get('h_goals')}-"
                       f"{m.get('a_goals')} {canonical(m.get('a_team'))}",
            "minutes": int(_f(m.get("time"))),
            "goals": int(_f(m.get("goals"))),
            "assists": int(_f(m.get("assists"))),
            "xG": round(_f(m.get("xG")), 2),
            "xA": round(_f(m.get("xA")), 2),
        })
    seasons = {}
    for m in matches:
        s = m.get("season")
        e = seasons.setdefault(s, {"season": s, "games": 0, "goals": 0,
                                   "assists": 0, "minutes": 0})
        e["games"] += 1
        e["goals"] += int(_f(m.get("goals")))
        e["assists"] += int(_f(m.get("assists")))
        e["minutes"] += int(_f(m.get("time")))
    return {
        "id": player_id,
        "name": agg.get("player_name") if agg else "",
        "team": canonical(agg.get("team_title")) if agg else "",
        "position": agg.get("position") if agg else "",
        "position_short": position_detail(agg.get("position"))[0] if agg else "",
        "position_long": position_detail(agg.get("position"))[1] if agg else "",
        "rating": value_rating(agg) if agg else None,
        "season": agg,
        "recent": recent,
        "history": sorted(seasons.values(),
                          key=lambda e: e["season"], reverse=True),
        "last5": last5_tally(matches),
    }
