"""Data-access layer. Every outside source goes through one adapter class here.

Layered architecture:
    Claude = reasoning/agent | MCP = data access | this package = prediction
    Providers = data | exchange/bookmaker feeds = market

All keys come from .env (never committed). Each adapter only uses documented,
terms-compliant endpoints.
"""
import csv
import io
import os
import time

import requests
from dotenv import load_dotenv

load_dotenv()

UA = {"User-Agent": "VendicatorModel/0.1 (research; contact site owner)"}


class ApiFootball:
    """Backbone: fixtures, live scores, lineups, injuries, odds, predictions.
    Free tier = 100 req/day -> cache aggressively. Upgrade to Pro/Mega scales
    the same endpoints with no code change."""

    BASE = "https://v3.football.api-sports.io"

    def __init__(self):
        self.key = os.environ["FOOTBALL_API_KEY"]
        self.session = requests.Session()
        self.session.headers.update({"x-apisports-key": self.key})

    def _get(self, path, **params):
        r = self.session.get(f"{self.BASE}/{path}", params=params, timeout=30)
        r.raise_for_status()
        return r.json()["response"]

    def fixtures(self, league_id, season, date=None):
        params = {"league": league_id, "season": season}
        if date:
            params["date"] = date
        return self._get("fixtures", **params)

    def live(self, league_ids=None):
        return self._get("fixtures", live="all" if not league_ids
                         else "-".join(map(str, league_ids)))

    def lineups(self, fixture_id):
        return self._get("fixtures/lineups", fixture=fixture_id)

    def injuries(self, league_id, season):
        return self._get("injuries", league=league_id, season=season)

    def odds(self, fixture_id):
        return self._get("odds", fixture=fixture_id)


class TheSportsDB:
    """Art layer: team badges, player photos, stadium images."""

    def __init__(self):
        self.key = os.getenv("THESPORTSDB_KEY", "3")  # 3 = free demo key
        self.base = f"https://www.thesportsdb.com/api/v1/json/{self.key}"

    def _get(self, path, **params):
        r = requests.get(f"{self.base}/{path}", params=params,
                         headers=UA, timeout=30)
        r.raise_for_status()
        return r.json()

    def team_badge(self, team_name):
        teams = self._get("searchteams.php", t=team_name).get("teams") or []
        return teams[0]["strBadge"] if teams else None

    def player_photo(self, player_name):
        players = self._get("searchplayers.php", p=player_name).get("player") or []
        return players[0].get("strCutout") or players[0].get("strThumb") \
            if players else None


class FootballDataOrg:
    """Secondary free feed: 12 top competitions, standings, schedules."""

    BASE = "https://api.football-data.org/v4"

    def __init__(self):
        self.headers = {"X-Auth-Token": os.getenv("FOOTBALL_DATA_ORG_KEY", "")}

    def _get(self, path, **params):
        r = requests.get(f"{self.BASE}/{path}", params=params,
                         headers=self.headers, timeout=30)
        r.raise_for_status()
        return r.json()

    def matches(self, competition):
        return self._get(f"competitions/{competition}/matches")

    def standings(self, competition):
        return self._get(f"competitions/{competition}/standings")


class OpenLigaDB:
    """Fully open German league data (no key needed)."""

    BASE = "https://api.openligadb.de"

    def matches(self, league="bl1", season=None):
        season = season or time.strftime("%Y")
        r = requests.get(f"{self.BASE}/getmatchdata/{league}/{season}",
                         headers=UA, timeout=30)
        r.raise_for_status()
        return r.json()


class FootballDataCoUk:
    """Historical results + closing odds CSVs -> training data for
    Dixon-Coles / Elo / ML. E0=Premier League, E1=Championship, D1=Bundesliga,
    SP1=La Liga, I1=Serie A, F1=Ligue 1 ... incl. lower leagues (E2, E3, EC)."""

    BASE = "https://www.football-data.co.uk/mmz4281"
    CACHE = None  # set to a pathlib.Path directory to cache downloads

    def season_csv(self, season_code, div):
        """season_code like '2526' for 2025/26; div like 'E0'.
        Caches to model/data/ so repeated backtests don't re-download."""
        cache = None
        if self.CACHE:
            cache = self.CACHE / f"fdcuk_{season_code}_{div}.csv"
            if cache.exists():
                return list(csv.DictReader(io.StringIO(
                    cache.read_text(errors="ignore"))))
        r = requests.get(f"{self.BASE}/{season_code}/{div}.csv",
                         headers=UA, timeout=60)
        r.raise_for_status()
        if cache:
            cache.write_text(r.text)
        return list(csv.DictReader(io.StringIO(r.text)))


class Understat:
    """Historical team xG per match (scrape-tolerated; TRAINING data only,
    never live). Leagues: EPL, La_liga, Bundesliga, Serie_A, Ligue_1, RFPL."""

    BASE = "https://understat.com"

    def matches(self, league="EPL", season="2023"):
        """Returns [{date, home, away, hg, ag, xg_home, xg_away}, ...].
        Uses the same getLeagueData endpoint the site's own frontend calls."""
        headers = dict(UA)
        headers.update({
            "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) "
                          "AppleWebKit/537.36",
            "Referer": f"{self.BASE}/league/{league}/{season}",
            "X-Requested-With": "XMLHttpRequest",
        })
        r = requests.get(f"{self.BASE}/getLeagueData/{league}/{season}",
                         headers=headers, timeout=60)
        r.raise_for_status()
        data = r.json()
        games = data.get("dates", [])
        out = []
        for g in games:
            if not g.get("isResult"):
                continue
            out.append({
                "date": g["datetime"][:10],
                "home": g["h"]["title"], "away": g["a"]["title"],
                "hg": int(g["goals"]["h"]), "ag": int(g["goals"]["a"]),
                "xg_home": float(g["xG"]["h"]), "xg_away": float(g["xG"]["a"]),
            })
        return out


class StatsBombOpen:
    """StatsBomb Open Data (free, GitHub) - event-level training data for the
    Phase-2 temporal Transformer / GNN prototypes."""

    BASE = ("https://raw.githubusercontent.com/statsbomb/open-data/master/"
            "data")

    def competitions(self):
        r = requests.get(f"{self.BASE}/competitions.json", headers=UA,
                         timeout=60)
        r.raise_for_status()
        return r.json()

    def matches(self, competition_id, season_id):
        r = requests.get(
            f"{self.BASE}/matches/{competition_id}/{season_id}.json",
            headers=UA, timeout=60)
        r.raise_for_status()
        return r.json()

    def events(self, match_id):
        r = requests.get(f"{self.BASE}/events/{match_id}.json", headers=UA,
                         timeout=120)
        r.raise_for_status()
        return r.json()


class Sportmonks:
    """Free tier: Danish Superliga + Scottish Premiership, full features
    (xG, predictions) -> use as the rich-data pilot leagues."""

    BASE = "https://api.sportmonks.com/v3/football"

    def __init__(self):
        self.token = os.getenv("SPORTMONKS_KEY", "")

    def _get(self, path, **params):
        params["api_token"] = self.token
        r = requests.get(f"{self.BASE}/{path}", params=params, timeout=30)
        r.raise_for_status()
        return r.json()["data"]

    def livescores(self):
        return self._get("livescores")

    def fixtures_between(self, start, end):
        return self._get(f"fixtures/between/{start}/{end}")
