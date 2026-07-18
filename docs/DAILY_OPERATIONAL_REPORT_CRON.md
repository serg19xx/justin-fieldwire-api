# Daily operational report CRON

Nightly job that builds one report per Active project (archive) plus one global summary, then emails **only the global summary** to Admin / Project Manager.

## One-time setup

```bash
cd /path/to/justin-fieldwire-api
php scripts/run-add-operational-daily-reports.php
# Adds snapshot/archive columns (rendered_html, title, report_type, scope) — idempotent
php scripts/run-add-report-snapshot-columns.php
```

## CLI

```bash
# Preview (default: yesterday) — no email
php scripts/run-daily-operational-report.php --dry-run

# Specific date — project snapshots + global summary (no email)
php scripts/run-daily-operational-report.php --date=2026-07-17 --dry-run

# Generate + email ONLY the global summary to Admin/PM
php scripts/run-daily-operational-report.php --date=2026-07-17 --send
```

Project-level reports are archive-only (view in the app). Email carries the compact global summary.

## Crontab example (after midnight, separate from outbox)

```cron
# Daily operational report — archive projects + email global summary
20 0 * * * cd /home/yjyhtqh8/fwapi.medicalcontractor.ca && /usr/local/bin/php scripts/run-daily-operational-report.php --send >> storage/logs/daily-op-report-cron.log 2>&1
```

Run outbox CRON independently (see `OUTBOX_CRON.md`).

## Storage

Table `fw_operational_daily_reports`: unique `(report_date, project_id)`, status `generated|sent|failed`.

Immutable snapshot columns:
- `payload_json` — facts at generation time (source of truth for Vue rendering)
- `rendered_html` — email-safe HTML snapshot (inline CSS, tables, CSS bars — no JS/SVG)
- `title`, `report_type` (`daily`), `scope` (`project` | `global`) — weekly/monthly later
- Global row uses `project_id = 0`

Regenerating the same `(date, project)` overwrites the snapshot (manual re-run only).

## Delivery

- Type: `DAILY_OPERATIONAL_REPORT`
- Channel: email (v1) — **global summary only** to Admin / Project Manager
- Correlation / idempotency: `daily-op-global:{date}:u{userId}`
- Project detail reports are never emailed (`scope=project` archive only)

## Read API (report archive)

Authorized (Bearer). Admin sees all; PM/foreman see their projects only.

```http
GET /api/v1/reports?type=daily&from=YYYY-MM-DD&to=YYYY-MM-DD&limit=200
GET /api/v1/reports/{id}            # metadata + payload_json (for Vue)
GET /api/v1/reports/{id}/view       # immutable rendered_html (new tab / print)
GET /api/v1/projects/{id}/reports   # archive for one project
```
