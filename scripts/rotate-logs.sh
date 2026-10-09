#!/usr/bin/env bash
# Weekly log rotation for shared hosting without logrotate.
# Each *.log in logs/ and storage/logs/ is gzipped to <name>-YYYYMMDD.log.gz and truncated
# in place (writers keep their open file handles). Archives older than KEEP_DAYS are deleted.
#
# Usage: bash scripts/rotate-logs.sh [--dry-run]
# Cron:  30 3 * * 0 cd /home/yjyhtqh8/fwapi.medicalcontractor.ca && bash scripts/rotate-logs.sh >> logs/rotate-logs.log 2>&1

set -u

KEEP_DAYS="${KEEP_DAYS:-60}"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d)"

for dir in "$ROOT/logs" "$ROOT/storage/logs"; do
  [ -d "$dir" ] || continue

  for log in "$dir"/*.log; do
    [ -f "$log" ] || continue
    [ -s "$log" ] || continue
    archive="${log%.log}-$STAMP.log.gz"
    [ -e "$archive" ] && continue
    size="$(du -h "$log" | cut -f1)"
    if [ "$DRY_RUN" -eq 1 ]; then
      echo "would rotate $log ($size) -> $archive"
      continue
    fi
    if gzip -c "$log" > "$archive"; then
      : > "$log"
      echo "rotated $log ($size) -> $(basename "$archive")"
    else
      rm -f "$archive"
      echo "FAILED to rotate $log" >&2
    fi
  done

  if [ "$DRY_RUN" -eq 1 ]; then
    find "$dir" -maxdepth 1 -name '*.log.gz' -mtime +"$KEEP_DAYS" -print | sed 's/^/would delete /'
  else
    find "$dir" -maxdepth 1 -name '*.log.gz' -mtime +"$KEEP_DAYS" -print -delete | sed 's/^/deleted /'
  fi
done

echo "[$(date -Iseconds)] rotate-logs done (keep ${KEEP_DAYS} days)"
