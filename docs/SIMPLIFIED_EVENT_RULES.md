# Simplified Event Rules (recipients on actions)

## Model

```
Event Type + Enabled + Severity + Priority + Execution Location + Comment
Actions (required, ≥1):
  - notify: channels + templates + recipients[]
  - create_report: period + recipients[]
  - log_only: audit only (no outbox)
Schedule filter (optional, crontab-like):
  - time_conditions: frequency (daily|weekly|monthly) + days_of_week / day_of_month + at_time / until_time + timezone
```

**Recipients live on each action**, not in conditions.  
**Schedule** answers “when may this rule run?” — leave empty to process immediately.

## Notify action

```json
{
  "type": "notify",
  "channels": ["email", "sms"],
  "recipients": ["project_manager", "task_lead", "admin"],
  "channel_content": {
    "email": { "mode": "local", "template_id": 12 },
    "sms": { "mode": "system" }
  }
}
```

Content modes (SendGrid / Twilio are **transport only**):
- `system` — auto title/body from event payload
- `local` — Message Template from Settings (`fw_message_templates`), rendered with Twig/`{{VAR}}`
- `manual` — subject/body stored on the rule, rendered with the same variables

Legacy `channel_templates: { "email": 12 }` is still accepted and mapped to `local`.

Resolved roles: `admin`, `project_manager`, `task_lead`, `team_members`, `foreman`, `worker`, `contractor`, `inspector`.

## Create report action (event-driven reports)

```json
{
  "type": "create_report",
  "period": "daily",
  "recipients": ["admin", "project_manager"]
}
```

Handled by `EventOutboxProcessor` → `DailyOperationalReportService::runForPeriod()`.

Any enabled rule with `create_report` + schedule is evaluated by tick CRON  
`php scripts/run-report-schedule-tick.php` (every 5–15 minutes).  
Periodicity comes from `time_conditions.frequency`, not from a special `REPORT_*` event-type name.

## Schedule (crontab-like)

```json
{
  "time_conditions": {
    "frequency": "monthly",
    "monthly_mode": "nth_weekday",
    "weekday_occurrence": 2,
    "days_of_week": [1],
    "months": [],
    "at_time": "07:00",
    "until_time": "08:00",
    "timezone": "America/New_York"
  }
}
```

Examples:
- 1st of each month → `monthly_mode: day_of_month`, `day_of_month: 1`
- Last day of month → `day_of_month_last: true`
- 2nd Monday → `monthly_mode: nth_weekday`, `weekday_occurrence: 2`, `days_of_week: [1]`
- Only Jan/Jul → `months: [1, 7]`

- **No schedule** → outbox processes immediately.
- **With schedule** → outbox uses it: in window = process; before window (same day) = defer; wrong day / after window = skip.
- Domain events are always logged; the schedule gate runs in `EventOutboxProcessor`.
- Schedule tick creates the event when the window matches (for `create_report` rules).

Legacy payloads (`business_hours_only`, `weekdays_only`, `time_range`) are still accepted and normalized.

## Removed / deprecated

- `conditions.notify_roles` → use `action.recipients`
- `store_for_dashboard`
- webhook channel in UI
- Project / Task conditions / Strict mode / Required|Preferred|Optional in UI

## Migration

```bash
php scripts/run-migrate-event-rule-recipients.php --dry-run
php scripts/run-migrate-event-rule-recipients.php
php scripts/run-migrate-report-type-unique.php
# seed REPORT_* rules
mysql ... < scripts/seed-report-schedule-event-rules.sql
```

## Manual test checklist

1. Create Notify rule with recipients PM + task_lead → outbox delivers to correct users.
2. Add schedule (e.g. weekly Mon 09:00–09:30) → daytime event outside window is deferred/skipped by outbox; in-window processes.
3. Enable any create_report rule + schedule → schedule tick in window → report archive + email to recipients.
4. Edit old rule that had notify_roles → recipients appear on Notify action after load.
5. Legacy time_range / weekdays_only schedules still match after normalize.
