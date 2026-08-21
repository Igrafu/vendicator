"""MODEL D (uncertainty half) - Bayesian hierarchical team strengths.

Gamma-Poisson conjugate model with league-level priors: every team's attack
and defence rate gets a full posterior distribution, shrunk toward the league
mean (hierarchical). The posterior spread feeds:
  - uncertainty bands on match probabilities (shown on site)
  - the rewards game: high-uncertainty matches pay more points (harder calls)

Upgrade path: PyMC/NumPyro for full MCMC once event-level data arrives.
"""
import numpy as np

PRIOR_MATCHES = 8.0  # strength of the league-mean prior (pseudo-matches)


class BayesianStrengths:
    def __init__(self, seed=7):
        self.rng = np.random.default_rng(seed)
        self.post = {}   # team -> dict of Gamma params

    def fit(self, matches):
        """matches: iterable of (home, away, hg, ag)."""
        goals_for, goals_against, played = {}, {}, {}
        total_goals, total_matches = 0, 0
        for home, away, hg, ag in matches:
            for team, gf, ga in ((home, hg, ag), (away, ag, hg)):
                goals_for[team] = goals_for.get(team, 0) + gf
                goals_against[team] = goals_against.get(team, 0) + ga
                played[team] = played.get(team, 0) + 1
            total_goals += hg + ag
            total_matches += 1
        league_rate = total_goals / (2 * total_matches)  # goals/team/match

        for team in played:
            n = played[team]
            # Gamma(shape, rate) posterior for scoring + conceding rates,
            # prior centred on league mean with PRIOR_MATCHES weight
            self.post[team] = {
                "atk_shape": league_rate * PRIOR_MATCHES + goals_for[team],
                "atk_rate": PRIOR_MATCHES + n,
                "def_shape": league_rate * PRIOR_MATCHES + goals_against[team],
                "def_rate": PRIOR_MATCHES + n,
            }
        self.league_rate = league_rate
        return self

    def sample_match(self, home, away, n_samples=4000, home_adv=1.25):
        """Posterior-predictive samples of (home_goals, away_goals)."""
        h, a = self.post[home], self.post[away]
        atk_h = self.rng.gamma(h["atk_shape"], 1 / h["atk_rate"], n_samples)
        def_a = self.rng.gamma(a["def_shape"], 1 / a["def_rate"], n_samples)
        atk_a = self.rng.gamma(a["atk_shape"], 1 / a["atk_rate"], n_samples)
        def_h = self.rng.gamma(h["def_shape"], 1 / h["def_rate"], n_samples)
        lam = home_adv * atk_h * def_a / self.league_rate
        mu = atk_a * def_h / self.league_rate
        return (self.rng.poisson(lam), self.rng.poisson(mu))

    def probs_fast(self, home, away, n_samples=1000):
        """1X2 probabilities only - no bootstrap, for bulk walk-forward."""
        hg, ag = self.sample_match(home, away, n_samples)
        return {"home": float((hg > ag).mean()),
                "draw": float((hg == ag).mean()),
                "away": float((hg < ag).mean())}

    def probs_with_uncertainty(self, home, away, n_samples=4000):
        """1X2 probabilities plus a 90% credible interval on the home-win
        probability - the site's uncertainty band."""
        hg, ag = self.sample_match(home, away, n_samples)
        p = {"home": float((hg > ag).mean()),
             "draw": float((hg == ag).mean()),
             "away": float((hg < ag).mean())}
        # bootstrap the samples to get spread on p_home
        idx = self.rng.integers(0, n_samples, (200, n_samples))
        boots = (hg[idx] > ag[idx]).mean(axis=1)
        p["home_ci90"] = [float(np.quantile(boots, 0.05)),
                          float(np.quantile(boots, 0.95))]
        p["uncertainty"] = float(boots.std())
        return p

    def difficulty_multiplier(self, home, away):
        """Rewards hook: points multiplier 1.0-2.0 scaled by outcome entropy
        (evenly matched, uncertain games pay more)."""
        p = self.probs_with_uncertainty(home, away, n_samples=2000)
        probs = np.array([p["home"], p["draw"], p["away"]])
        probs = probs[probs > 0]
        entropy = -(probs * np.log(probs)).sum() / np.log(3)
        return round(1.0 + entropy, 2)
