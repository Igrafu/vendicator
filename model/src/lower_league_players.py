"""Squads for the leagues Understat does not cover.

Understat carries the top five European leagues and nothing else, which
leaves the Championship, League One, League Two, La Liga 2, Serie B and the
rest of the board with no player markets at all.

Two free sources were tried. API-Football's free plan caps the page
parameter at 3 and strips per-player statistics, so it yields sixty names a
league with every number zeroed - not enough to price anything.
TheSportsDB's team endpoint does better: real squads with detailed
positions, shirt numbers and an active/injured status, ten players a club
on the free tier.

So this harvests squads club by club and caches them. What it does NOT
provide is per-player season statistics - no free source publishes those
below the top five leagues. Markets for these players are therefore priced
from position and the fixture's own expected goals, and the site says so
rather than implying we have form data we do not have.

Run:  .venv/bin/python model/src/lower_league_players.py --league E1
      .venv/bin/python model/src/lower_league_players.py --all
"""
import argparse
import json
import time
from datetime import datetime, timezone
from pathlib import Path

import requests

from teams import canonical

ROOT = Path(__file__).resolve().parents[2]
CACHE = ROOT / "model" / "data" / "squads"
BASE = "https://www.thesportsdb.com/api/v1/json/3"
# the plain requests default is refused; this is the ordinary browser string
UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/120 Safari/537.36")
TTL = 14 * 24 * 3600        # squads change slowly; refresh fortnightly

# Position -> the shape of a player's contribution, used to price markets
# where no per-player statistics exist. Goals and assists per 90 are league
# averages by role, scaled at run time by the fixture's expected goals.
ROLE_RATES = {
    "GK": {"goals": 0.001, "assists": 0.005, "shots": 0.02,
           "key_passes": 0.05, "tackles": 0.2},
    "DEF": {"goals": 0.045, "assists": 0.05, "shots": 0.55,
            "key_passes": 0.55, "tackles": 2.4},
    "MID": {"goals": 0.10, "assists": 0.12, "shots": 1.1,
            "key_passes": 1.3, "tackles": 1.9},
    "FWD": {"goals": 0.34, "assists": 0.13, "shots": 2.3,
            "key_passes": 1.0, "tackles": 0.7},
}

POSITION_GROUP = (
    ("Goalkeeper", "GK"),
    ("Centre-Back", "DEF"), ("Right-Back", "DEF"), ("Left-Back", "DEF"),
    ("Defender", "DEF"), ("Full-Back", "DEF"), ("Wing-Back", "DEF"),
    ("Defensive Midfield", "MID"), ("Central Midfield", "MID"),
    ("Attacking Midfield", "MID"), ("Midfielder", "MID"), ("Midfield", "MID"),
    ("Winger", "FWD"), ("Forward", "FWD"), ("Striker", "FWD"),
    ("Centre-Forward", "FWD"), ("Attacker", "FWD"),
)


def position_group(raw):
    """'Right-Back' -> 'DEF'. Falls back to midfield, the safest guess."""
    s = (raw or "").strip()
    for needle, group in POSITION_GROUP:
        if needle.lower() in s.lower():
            return group
    return "MID"


def _session():
    s = requests.Session()
    s.headers.update({"User-Agent": UA,
                      "Accept": "application/json,text/plain,*/*"})
    return s


def _get(session, path, params, tries=4):
    """GET with backoff. The free tier throttles hard and answers 429 to a
    burst, so a squad harvest is paced rather than hammered."""
    delay = 2.0
    for attempt in range(tries):
        try:
            r = session.get(f"{BASE}/{path}", params=params, timeout=45)
            if r.status_code == 429:
                time.sleep(delay)
                delay *= 2
                continue
            r.raise_for_status()
            return r.json()
        except requests.HTTPError:
            if attempt == tries - 1:
                raise
            time.sleep(delay)
            delay *= 2
    return {}


def _cached(name, fetch, ttl=TTL):
    CACHE.mkdir(parents=True, exist_ok=True)
    f = CACHE / f"{name}.json"
    if f.exists() and time.time() - f.stat().st_mtime < ttl:
        try:
            return json.loads(f.read_text())
        except ValueError:
            pass
    data = fetch()
    f.write_text(json.dumps(data, indent=1))
    return data


# Short names in our registry that TheSportsDB does not answer to. It wants
# the full club name; "Bolton" returns nothing, "Bolton Wanderers" works.
SEARCH_SUFFIXES = (" Wanderers", " City", " Town", " United", " FC",
                   " Athletic", " Rovers", " County", " Albion")


def team_id(session, team):
    """TheSportsDB id for a club name, cached.

    Tries the name as given, then the same name with the usual English club
    suffixes, because our canonical names are deliberately short.
    """
    def fetch():
        for candidate in (team,) + tuple(team + s for s in SEARCH_SUFFIXES):
            rows = (_get(session, "searchteams.php", {"t": candidate})
                    .get("teams") or [])
            for row in rows:
                if (row.get("strSport") or "Soccer") != "Soccer":
                    continue
                return {"id": row.get("idTeam"), "name": row.get("strTeam"),
                        "league": row.get("strLeague"), "matched": candidate}
            time.sleep(1.2)
        return {}
    slug = "".join(c if c.isalnum() else "_" for c in team.lower())
    return _cached(f"id_{slug}", fetch)


def squad(team):
    """Cached squad for one club -> list of player dicts."""
    session = _session()
    info = team_id(session, team)
    if not info.get("id"):
        return []

    def fetch():
        return (_get(session, "lookup_all_players.php", {"id": info["id"]})
                .get("player") or [])
    rows = _cached(f"squad_{info['id']}", fetch)

    canon = canonical(team)
    out = []
    for row in rows:
        name = row.get("strPlayer")
        if not name:
            continue
        group = position_group(row.get("strPosition"))
        status = (row.get("strStatus") or "").strip().lower()
        out.append({
            "id": f"tsdb{row.get('idPlayer')}",
            "name": name,
            "team": canon,
            "position": row.get("strPosition") or "",
            "position_short": group,
            "position_long": row.get("strPosition") or "Position not recorded",
            "number": (row.get("strNumber") or "").strip(),
            "source": "thesportsdb",
            # anything other than an explicit "active" is worth surfacing,
            # but only "injured" is ever presented as an injury
            "status": status,
        })
    return out


def synthesise_stats(player, team_expected_goals=1.3):
    """Give a squad-only player the numbers the market builder needs.

    There are no recorded per-player statistics for these leagues on any
    free feed. Rather than leave the markets empty, rates are taken from the
    player's position and scaled by how many goals this fixture expects that
    side to score. The site labels these as positional estimates.
    """
    rates = ROLE_RATES[player.get("position_short", "MID")]
    scale = max(min(team_expected_goals / 1.35, 2.0), 0.4)
    games, minutes = 20, 20 * 72
    est = {
        "games": games,
        "minutes": minutes,
        "goals": round(rates["goals"] * scale * games, 1),
        "assists": round(rates["assists"] * scale * games, 1),
        "shots": round(rates["shots"] * scale * games, 1),
        "key_passes": round(rates["key_passes"] * games, 1),
        "tackles": int(round(rates["tackles"] * games)),
        "yellow_cards": 3, "red_cards": 0,
        "xG": round(rates["goals"] * scale * games, 2),
        "xA": round(rates["assists"] * scale * games, 2),
    }
    merged = dict(player)
    merged.update(est)
    merged["estimated"] = True
    return merged


def harvest(teams, pause=0.5):
    """Cache squads for a list of clubs. Returns how many were reached."""
    got = 0
    for team in teams:
        try:
            rows = squad(team)
            if rows:
                got += 1
            print(f"  {team}: {len(rows)} players")
        except Exception as e:
            print(f"  {team}: {str(e)[:70]}")
        time.sleep(pause)
    return got


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--league", default="E1")
    ap.add_argument("--all", action="store_true")
    args = ap.parse_args()

    import json as _json
    log = ROOT / "records" / "season-log.jsonl"
    by_league = {}
    for line in log.read_text().splitlines():
        if not line.strip():
            continue
        m = _json.loads(line)
        by_league.setdefault(m["competition"], set()).update(
            [m["home"], m["away"]])

    codes = list(by_league) if args.all else args.league.split(",")
    total = 0
    for code in codes:
        teams = sorted(by_league.get(code, []))
        if not teams:
            continue
        print(f"=== {code} ({len(teams)} clubs) ===")
        total += harvest(teams)
    print(f"\n{total} squads cached -> {CACHE}")
    print(f"generated {datetime.now(timezone.utc).isoformat()}")


if __name__ == "__main__":
    main()
