#!/bin/sh
# Vendicator daily pipeline - free data only.
# predictions -> site push -> settlement -> injury snapshots -> archive
# -> record exports -> git. Scheduled via cron; safe to run manually.
cd "/Volumes/External SSD/Workbase/Vindicator" || exit 1
PY=../../.venv/bin/python
LOG=model/output/daily_run.log
{
    echo "=== daily run $(date -u '+%Y-%m-%d %H:%M UTC') ==="
    (cd model/src && $PY run_pipeline.py --upcoming)
    (cd model/src && $PY settle.py)
    (cd model/src && $PY snapshot_prematch.py --season 2023 \
        --leagues E0,SP1,I1,D1,F1)
    .venv/bin/python model/src/build_archive.py
    .venv/bin/python model/src/export_records.py
    .venv/bin/python model/src/export_records_pdf.py
    git add records model/output model/data
    git -c user.name="Igrafu" -c user.email="eljay00p@gmail.com" \
        commit -q -m "Daily data run $(date -u +%F)" && git push -q
    echo "=== done ==="
} >> "$LOG" 2>&1
