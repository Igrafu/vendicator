"""PLAYER BRAIN, stage 2: reward rules + progression.

Contextual bandit (bandits.py) picks WHICH offer to show; this module defines
what each offer concretely does, and the deterministic scoring rules that
users must be able to trust (never bandit-controlled):

  - prediction points use a proper scoring rule (Brier-based), scaled by the
    Bayesian difficulty multiplier -> hard, uncertain matches pay more
  - streaks, XP, badges, tiers follow fixed published rules

RL roadmap: once enough engagement data exists, replace the bandit's
one-step objective with an RL policy optimising long-term retention
(episode = a user's season journey). Not before.
"""

BASE_POINTS = 100

OFFERS = {
    "points_50": {"type": "bonus_points", "amount": 50,
                  "label": "+50 bonus points"},
    "double_points_tonight": {"type": "multiplier", "amount": 2.0,
                              "expires": "midnight",
                              "label": "Double points on tonight's matches"},
    "streak_challenge": {"type": "challenge", "target": 3,
                         "reward": 150,
                         "label": "3 correct in a row -> +150"},
    "leaderboard_invite": {"type": "competition",
                           "label": "Join this week's league challenge"},
    "free_token": {"type": "token", "amount": 1,
                   "label": "Free premium prediction token"},
    "hard_pick_bonus": {"type": "multiplier_hard", "amount": 1.5,
                        "label": "50% extra on high-uncertainty picks"},
}

TIERS = [(0, "free"), (1000, "bronze"), (5000, "silver"), (15000, "gold")]

# Subscription catalogue: users upgrade with EARNED points (lifetime points
# unlock eligibility; upgrading never spends the balance). Each rank buys
# better predictions, better odds views and more customization.
SUBSCRIPTIONS = {
    "free": {
        "points_required": 0,
        "benefits": ["T3 lower-league predictions", "1X2 + BTTS markets",
                     "single-bookmaker odds view", "standard profile"],
    },
    "bronze": {
        "points_required": 1000,
        "benefits": ["T2 leagues unlocked", "over/under markets",
                     "daily value pick", "profile badge colours"],
    },
    "silver": {
        "points_required": 5000,
        "benefits": ["T1 top leagues unlocked",
                     "exact score + handicap markets",
                     "uncertainty bands on every prediction",
                     "multi-bookmaker odds comparison",
                     "custom dashboard themes"],
    },
    "gold": {
        "points_required": 15000,
        "benefits": ["early prediction release", "full odds + value engine",
                     "private competitions", "badge & avatar customization",
                     "priority rewards catalogue"],
    },
}


def upgrade_eligible(lifetime_points, current_tier):
    """Tiers the user can upgrade to right now, based on earned points."""
    order = list(SUBSCRIPTIONS)
    return [t for t in order[order.index(current_tier) + 1:]
            if lifetime_points >= SUBSCRIPTIONS[t]["points_required"]]


def prediction_points(user_probs, outcome, difficulty=1.0, multiplier=1.0):
    """Brier-based proper scoring: confident-and-right beats hedging,
    wrong-and-confident loses. user_probs: {'H':p,'D':p,'A':p}."""
    b = sum((p - (1.0 if k == outcome else 0.0)) ** 2
            for k, p in user_probs.items())
    # b in [0, 2]; 2/3 is the uniform-guess score -> zero points there
    raw = (2 / 3 - b / 2) / (2 / 3) * BASE_POINTS
    return int(round(max(raw, -BASE_POINTS // 2) * difficulty * multiplier))


def streak_bonus(streak_len):
    return {3: 150, 5: 400, 10: 1500}.get(streak_len, 0)


def tier_for(lifetime_points):
    tier = TIERS[0][1]
    for threshold, name in TIERS:
        if lifetime_points >= threshold:
            tier = name
    return tier


def league_access(tier):
    """Rank gates league tiers: lower leagues for entry ranks (site rule)."""
    return {"free": ["T3"], "bronze": ["T3", "T2"],
            "silver": ["T3", "T2", "T1"],
            "gold": ["T3", "T2", "T1", "extras"]}[tier]
