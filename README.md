# Vindicator — Football Prediction & Rewards Platform

Football score/betting-market prediction engine plus a WordPress (Bricks Builder)
front end with user accounts, points, rankings and rewards.

## Layout

| Path | Purpose |
|---|---|
| `model/` | Prediction engine (Node.js): fetch fixtures/odds, run model ensemble, emit `model/output/predictions.json` |
| `records/accounts.json` | Local master record of users, points, rewards, invoices (synced down from WordPress) |
| `records/model-history.jsonl` | Append-only log of every prediction + actual result, for evaluation and retraining |
| `wp-deploy/` | WordPress plugin/theme code shipped to the Hostinger staging site |
| `docs/` | Architecture and model plan |

## Workflow

Claude → NovaMira → Bricks page build → browser test (desktop/tablet/mobile)
→ fix → console/network check → retest → GitHub → Hostinger.

Secrets live in `.env` (untracked). See `.env.example`.
