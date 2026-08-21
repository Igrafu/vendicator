"""Pre-match live features: injuries + confirmed lineups from API-Football.

Used at PREDICTION time (these can't be backtested on the free tier - no
historical injury snapshots), where they matter most: the market is slowest
to fully price in late team news.

Budget-aware: free tier = 100 requests/day, so every response is cached to
model/data/live_cache/ and refreshed at most every 6 hours per key.
"""
import json
import time
from pathlib import Path

from adapters import ApiFootball

CACHE_DIR = Path(__file__).resolve().parents[2] / "model" / "data" \
    / "live_cache"
CACHE_TTL = 6 * 3600


def _cached(key, fetch):
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    f = CACHE_DIR / f"{key}.json"
    if f.exists() and time.time() - f.stat().st_mtime < CACHE_TTL:
        return json.loads(f.read_text())
    data = fetch()
    f.write_text(json.dumps(data))
    return data


def injury_counts(league_id, season):
    """{team_name: number of currently listed injuries/suspensions}."""
    api = ApiFootball()
    data = _cached(f"injuries_{league_id}_{season}",
                   lambda: api.injuries(league_id, season))
    counts = {}
    for item in data:
        team = (item.get("team") or {}).get("name")
        if team:
            counts[team] = counts.get(team, 0) + 1
    return counts


def lineup_signal(fixture_id):
    """Once lineups are confirmed (~1h before kickoff):
    returns {'home_starters': [...], 'away_starters': [...], 'confirmed': bool}
    The engine compares starters vs the team's usual XI to flag rotation."""
    api = ApiFootball()
    data = _cached(f"lineup_{fixture_id}",
                   lambda: api.lineups(fixture_id))
    out = {"confirmed": len(data) >= 2, "home_starters": [],
           "away_starters": []}
    for i, side in enumerate(("home_starters", "away_starters")):
        if len(data) > i:
            out[side] = [p["player"]["name"]
                         for p in data[i].get("startXI", [])]
    return out


def prematch_adjustment(injury_count_home, injury_count_away, per_injury=0.03,
                        cap=0.12):
    """Convert injury burden difference into a goal-rate multiplier pair.
    Small, capped effect: (home_mult, away_mult)."""
    delta = min(injury_count_away * per_injury, cap) \
        - min(injury_count_home * per_injury, cap)
    return 1.0 + delta / 2, 1.0 - delta / 2


if __name__ == "__main__":
    # smoke test: Premier League current injuries (1 request, then cached)
    counts = injury_counts(39, 2023)
    top = sorted(counts.items(), key=lambda kv: -kv[1])[:5]
    print(f"{sum(counts.values())} listed injuries across "
          f"{len(counts)} teams; most: {top}")
