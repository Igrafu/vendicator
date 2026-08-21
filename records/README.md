# Records

## accounts.json
Master local copy of site users, points, rewards and invoices. Synced down from
WordPress via REST API. WordPress is the source of truth for live balances; this
file is the audit/history copy.

## model-history.jsonl
Append-only. One JSON object per line, one line per market prediction:

```json
{"ts":"2026-08-21T14:00:00Z","match_id":"...","league":"...","kickoff":"...",
 "home":"...","away":"...","model":"dixon-coles|elo|lgbm|devig|ensemble",
 "market":"1x2|btts|ou25|exact|dc|ah|shots|scorers",
 "prediction":{},"probs":{},"fair_odds":{},"book_odds":{},
 "result":null,"settled":false,
 "eval":{"brier":null,"logloss":null,"clv":null}}
```

`result`/`eval` are filled in by the settlement job after full time. Never edit
or delete existing lines — corrections are appended as new lines with
`"correction_of": "<ts+match_id>"`.
