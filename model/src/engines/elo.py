"""Club Elo with home advantage and a Davidson draw model.

Output is always probabilities (0-1); the presentation layer renders
percentages, e.g. home 54.2% / draw 26.1% / away 19.7%.
"""
import math


class EloModel:
    def __init__(self, k=24.0, home_adv=65.0, draw_nu=0.29, base=1500.0):
        self.k = k
        self.home_adv = home_adv
        self.draw_nu = draw_nu  # Davidson draw parameter
        self.base = base
        self.ratings = {}

    def rating(self, team):
        return self.ratings.get(team, self.base)

    def probs(self, home, away):
        """Return {'home': p, 'draw': p, 'away': p}."""
        dr = self.rating(home) + self.home_adv - self.rating(away)
        ph_raw = 1.0 / (1.0 + 10 ** (-dr / 400.0))
        pa_raw = 1.0 - ph_raw
        # Davidson: draw mass proportional to geometric mean of win probs
        pd = self.draw_nu * math.sqrt(ph_raw * pa_raw) * 2.0
        scale = 1.0 - pd
        return {"home": ph_raw * scale, "draw": pd, "away": pa_raw * scale}

    def update(self, home, away, home_goals, away_goals):
        p = self.probs(home, away)
        exp_home = p["home"] + 0.5 * p["draw"]
        score = 1.0 if home_goals > away_goals else \
            0.5 if home_goals == away_goals else 0.0
        # margin-of-victory multiplier (mild, log-shaped)
        mov = math.log(abs(home_goals - away_goals) + 1.0) + 1.0
        delta = self.k * mov * (score - exp_home)
        self.ratings[home] = self.rating(home) + delta
        self.ratings[away] = self.rating(away) - delta

    def fit(self, matches):
        """matches: iterable of (home, away, hg, ag) in chronological order."""
        for home, away, hg, ag in matches:
            self.update(home, away, hg, ag)
        return self
