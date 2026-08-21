"""PLAYER BRAIN, stage 1: contextual bandits.

Decides what each user should see/do/be rewarded next: which bonus to offer,
which challenge or competition to surface, which prediction market to
recommend. Contextual bandits first (simple, safe, online); full RL only
later for long-horizon mechanics (see rewards.py roadmap).

Never controls prediction probabilities - Prediction Brain and Player Brain
are strictly separated.
"""
import numpy as np


class LinUCB:
    """Linear UCB contextual bandit.

    arms: list of action names, e.g.
      ["points_50", "double_points_tonight", "streak_challenge",
       "leaderboard_invite", "free_token", "hard_pick_bonus"]
    context vector per user, e.g.
      [days_active, current_streak, fav_team_plays_today, avg_stake_conf,
       predictions_this_week, rank_percentile]
    reward signal: 1 if the user engaged with the offer, else 0.
    """

    def __init__(self, arms, dim, alpha=1.2):
        self.arms = list(arms)
        self.dim = dim
        self.alpha = alpha
        self.A = {a: np.eye(dim) for a in self.arms}      # d x d
        self.b = {a: np.zeros(dim) for a in self.arms}

    def choose(self, context):
        x = np.asarray(context, dtype=float)
        scores = {}
        for a in self.arms:
            A_inv = np.linalg.inv(self.A[a])
            theta = A_inv @ self.b[a]
            scores[a] = float(theta @ x
                              + self.alpha * np.sqrt(x @ A_inv @ x))
        return max(scores, key=scores.get), scores

    def update(self, arm, context, reward):
        x = np.asarray(context, dtype=float)
        self.A[arm] += np.outer(x, x)
        self.b[arm] += reward * x

    def to_state(self):
        return {a: {"A": self.A[a].tolist(), "b": self.b[a].tolist()}
                for a in self.arms}

    @classmethod
    def from_state(cls, state, alpha=1.2):
        arms = list(state)
        dim = len(state[arms[0]]["b"])
        obj = cls(arms, dim, alpha)
        for a in arms:
            obj.A[a] = np.array(state[a]["A"])
            obj.b[a] = np.array(state[a]["b"])
        return obj


class ThompsonBeta:
    """Beta-Bernoulli Thompson sampling - lighter alternative for binary
    engage/ignore offers with no context (e.g. picking tonight's featured
    competition globally)."""

    def __init__(self, arms, seed=11):
        self.rng = np.random.default_rng(seed)
        self.params = {a: [1.0, 1.0] for a in arms}   # alpha, beta

    def choose(self):
        draws = {a: self.rng.beta(*p) for a, p in self.params.items()}
        return max(draws, key=draws.get)

    def update(self, arm, engaged):
        self.params[arm][0 if engaged else 1] += 1.0
