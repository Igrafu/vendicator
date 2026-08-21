# Vendicator — Six-Engine Architecture

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
| xG-Poisson (Understat rolling xG) | 0.5928 | 0.9935 |
| Elo | 0.5908 | 0.9866 |
| **Stacked + calibrated** | **0.5938** | **0.9946** |
| Stacked (no market input) | 0.5998 | 1.0035 |
| Dixon-Coles | 0.6271 | 1.0415 |
| Bayesian | 0.6366 | 1.0583 |
| Tabular (4 members) | 0.7388 | 1.2639 |

xG integration (2026-08-21): three seasons of Understat team xG cached in
`model/data/` via the site's own getLeagueData endpoint; rolling xG/xGA are
tabular features and drive the standalone xG-Poisson base model — the
strongest non-market signal. The odds-less ensemble improved 0.6035 → 0.5998.

Reading: the market is the hardest benchmark (as expected — beating the
closing line is the long game). The ensemble already beats every non-market
model and sits ~1pt of Brier behind the market with only free historical
data. Improvement levers, in order: xG features (Understat/StatsBomb),
lineups/injuries (API-Football), all four GBM members, per-league meta
weights, longer training windows.

## Data archive (2026-08-21)

`records/sports-archive.jsonl`: 45,850 matches, Aug 2016 → present, across 10
league divisions (E0-E3, SP1/SP2, I1/I2, D1, F1) + 6 cups (UCL, UEL, FA Cup,
EFL Cup, Copa del Rey, Coppa Italia); 1,246 teams in
`records/teams-registry.json`. Rebuilt idempotently by
`model/src/build_archive.py` after every data refresh.
`records/injury-history.jsonl`: daily injury snapshots via
`model/src/snapshot_prematch.py` (cron) - building the pre-match injury
dataset no free source offers. Current-season snapshots need API-Football Pro.

Temporal prototype (300 StatsBomb matches, 5,400 snapshots): live Brier
0.6898 vs analytic baseline 0.5472 - gap closing with data volume
(was 0.8825 at 50 matches); analytic engine stays default until beaten.

## Phase gates (each needs sign-off before spend)

1. **Phase 2** — temporal Transformer + GNN training: needs paid event-level
   feed (Sportmonks advanced or similar) and likely a small GPU/VPS.
2. **Phase 3** — Kafka/Feast/MLflow/Postgres stack: needs a VPS (Hostinger
   WP hosting cannot run these); revisit at real user volume.
3. RL for rewards: only after months of bandit engagement logs exist.
