#!/bin/sh
# Vendicator settlement run - hourly.
#
# The daily pipeline rebuilds the whole board once a morning, which is fine
# for prices but too slow for results: a card that finished at 17:00 should
# not still be sitting unsettled at midnight. This does the light half of
# the work every hour - settle whatever has finished, re-score the teams
# involved, and push the refreshed board - so Scorelines and member points
# follow the football rather than the clock.
#
# Safe to run manually; it is a no-op when nothing has finished.
cd "/Volumes/External SSD/Workbase/Vindicator" || exit 1
PY=.venv/bin/python
LOG=model/output/settle_run.log

{
    echo "=== settle run $(date -u '+%Y-%m-%d %H:%M UTC') ==="
    (cd model/src && ../../$PY settle.py)
    # re-score and re-grade from the settled history, then publish
    $PY - <<'PYEOF'
import json
import sys
from pathlib import Path

sys.path.insert(0, "model/src")
from run_pipeline import OUTPUT, push_to_wp
from scoreline import build as scoreline_build

if not OUTPUT.exists():
    print("no board to refresh")
    raise SystemExit(0)
payload = json.loads(OUTPUT.read_text())
scoreline_build(payload)
OUTPUT.write_text(json.dumps(payload, indent=2))
push_to_wp(payload)
PYEOF
    echo "=== done ==="
} >> "$LOG" 2>&1
