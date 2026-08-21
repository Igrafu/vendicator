# Vindicator — Six-Engine Architecture

Two strictly separated AI brains:
- **Prediction Brain** — "what is likely to happen?" (engines below)
- **Player Brain** — "what should this player see/do/be rewarded next?"
  (contextual bandits → RL later). It never touches probabilities; scoring
  rules users must trust are fixed and published (`game/rewards.py`).

The LLM (Claude) sits ONLY in the explanation layer (`llm_explain.py`):
injuries/news interpretation, tactical summaries, personalised previews,
"why does the model favour X". It never produces a probability.

```
                    FOOTBALL DATA (adapters.py)
                         │
       ┌─────────────────┼─────────────────┐
       ▼                 ▼                 ▼
   Historical        Live Events       Player Data
   (FD.co.uk CSVs)   (API-Football)    (API-Football/TheSportsDB)
       └─────────────────┼─────────────────┘
                ┌────────▼─────────┐
                │ FEATURE ENGINE   │  features.py — form, rolling attack/
                │                  │  defence, shots, rest days, MARKET AS
                └────────┬─────────┘  INPUT (de-vig, movement, disagreement)
       ┌─────────────────┼────────────────────┐
       ▼                 ▼                    ▼
  MODEL A            MODEL B              MODEL C
  Tabular ML         Temporal/live        GNN/Spatial
  engines/tabular    engines/temporal     engines/graph
  LGBM+XGB+Cat       InPlayEngine now;    stub + rule-based
  +HistGBM fallback  Transformer Phase 2  TacticalReport; GNN Phase 2
       │                 │                    │
       │            MODEL D — Statistical: engines/elo, dixon_coles,
       │            bayesian (hierarchical Gamma-Poisson, uncertainty)
       └─────────────────┼────────────────────┘
                  ENSEMBLE ENGINE   engines/ensemble.py — stacking meta-
                         │          learner (learns when to trust whom)
                  Bayesian/Platt/isotonic calibration
                         ▼
                PROBABILITY ENGINE  → calibrated % for every market
                         ▼
                MONTE CARLO ENGINE  engines/montecarlo.py — league/title/
                         │          relegation sims, scenarios
       ┌─────────────────┼─────────────────┐
       ▼                 ▼                 ▼
   predictions       simulations        scenarios
       └─────────────────┼─────────────────┘
                 REWARD / GAME ENGINE   game/bandits.py + game/rewards.py
                         │              LinUCB + Thompson → offers, streaks,
                         │              XP, badges, difficulty multipliers
                         ▼
                    USER EXPERIENCE  (WordPress/Bricks + Claude explanations)
```

## Component → technology (status)

| Component | Technology | Status |
|---|---|---|
| Core language | Python 3.9 (venv) | live |
| Data processing | pandas now; Polars + DuckDB when volume demands | planned swap |
| Baseline | Elo + Dixon-Coles | **live** |
| Tabular ML | LightGBM + XGBoost + CatBoost + HistGBM | **live** |
| Uncertainty | Hierarchical Gamma-Poisson (PyMC upgrade path) | **live** |
| Live prediction | Analytic InPlayEngine; temporal Transformer | **live** / Phase 2 |
| Player/team graph | GNN | Phase 2 (needs event data) |
| Tracking data | Spatiotemporal NN | Phase 3 (needs tracking feed) |
| Ensemble | Stacking meta-learner (per-model trust) | **live** |
| Calibration | Isotonic + Platt/logistic | **live** |
| Scenarios | Monte Carlo | **live** |
| Reward personalisation | LinUCB / Thompson contextual bandits | **live** |
| Long-term rewards | Reinforcement learning | Phase 3 (needs engagement data) |
| News/injury NLP + explanations | Claude (explanation layer only) | **live** (context builder) |
| Real-time events | Kafka/Redpanda | Phase 3 (needs VPS) |
| Feature store / tracking | Feast / MLflow | Phase 3 (needs VPS) |
| Serving | FastAPI (+ ONNX) | Phase 2 (needs VPS/serverless) |
| Database | PostgreSQL + TimescaleDB | Phase 3 (WP/MySQL + files now) |

## Four specialist models, then

stacking → calibration → Monte Carlo — never one giant network.

## Backtest snapshot (E0, seasons 22/23–24/25, walk-forward 60/20/20)

| Model | Brier | Log loss |
|---|---|---|
| De-vigged market (B365) | 0.5811 | 0.9734 |
| Elo | 0.5908 | 0.9866 |
| **Stacked + calibrated** | **0.5938** | **0.9948** |
| Stacked (no market input) | 0.6035 | 1.0086 |
| Dixon-Coles | 0.6271 | 1.0415 |
| Bayesian | 0.6366 | 1.0583 |
| Tabular (4 members) | 0.7082 | 1.2132 |

Reading: the market is the hardest benchmark (as expected — beating the
closing line is the long game). The ensemble already beats every non-market
model and sits ~1pt of Brier behind the market with only free historical
data. Improvement levers, in order: xG features (Understat/StatsBomb),
lineups/injuries (API-Football), all four GBM members, per-league meta
weights, longer training windows.

## Phase gates (each needs sign-off before spend)

1. **Phase 2** — temporal Transformer + GNN training: needs paid event-level
   feed (Sportmonks advanced or similar) and likely a small GPU/VPS.
2. **Phase 3** — Kafka/Feast/MLflow/Postgres stack: needs a VPS (Hostinger
   WP hosting cannot run these); revisit at real user volume.
3. RL for rewards: only after months of bandit engagement logs exist.
