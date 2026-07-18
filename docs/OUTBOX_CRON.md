# Event outbox CRON

## Tables

| Table | Purpose |
|-------|---------|
| `fw_event_log` | Audit journal of what happened in the system |
| `fw_event_outbox` | Queue of actions from Event Rules (`notify`, `create_report`, …) |
| `fw_notifications` | Delivery result per channel (email / SMS / push) |

## Worker

CLI (preferred):

```bash
cd /home/.../fwapi.medicalcontractor.ca
php scripts/process-event-outbox.php 100
```

HTTP (admin/manual):

```http
POST /api/v1/events/process?limit=100
Authorization: Bearer <token>
```

## Crontab example

```cron
* * * * * cd /home/yjyhtqh8/fwapi.medicalcontractor.ca && /usr/bin/php scripts/process-event-outbox.php 100 >> storage/logs/outbox-cron.log 2>&1
```

On cPanel: Cron Jobs → every minute → same command.

## Instant vs outbox

- **Instant** (field work start/end, project Active/Inactive): sent immediately via `NotificationDispatcher`. Outbox `notify` for these event types is skipped to avoid duplicates.
- **Event Rules notify**: written to outbox, delivered by this worker.

Daily operational email reports are a separate evening job — see `DAILY_OPERATIONAL_REPORT_CRON.md`.
