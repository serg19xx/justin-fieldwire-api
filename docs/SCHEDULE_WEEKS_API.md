# Weekly schedule API (`schedule-weeks`)

## Database

- Run `scripts/create-schedule-weeks-tables.sql`.
- **`fw_schedule_weeks`**: one row per `(project_id, week_start)` where `week_start` is the **Monday** (ISO) of the week. `status`: `draft` | `published`.
- **`fw_worker_task_schedules`**: slots for a worker on a **calendar** `work_date`, with `day_part`:
  - `am` — morning
  - `pm` — afternoon
  - `full` — full day
- **Foreign keys**: week and project CASCADE with parent; task row uses `ON DELETE CASCADE` on `fw_prj_tasks` (schedule lines are removed if the task is deleted).
- **Uniqueness**: `(schedule_week_id, user_id, work_date, day_part)` — at most one slot per user/date/part in a given week document.

## RBAC

- **Manage** (create draft, replace entries, publish): global roles `admin`, `project_manager`, or user is `fw_projects.prj_manager` for the project.
- **View project week** (`GET .../schedule-weeks`): managers as above, or any user listed in `fw_prj_team_members` for that project.
- **Own schedule** (`GET /api/v1/me/schedule`): any authenticated user; **published** weeks only.
- **User schedule (cross-project)** (`GET /api/v1/users/{userId}/schedule`): same published data shape as `/me/schedule`, for conflict checking when PM plans someone else. Allowed if the caller is the same user, or has role `admin` / `project_manager`, or is `fw_projects.prj_manager` on at least one project where the target user appears in `fw_prj_team_members`. Otherwise **403**. Optional org-wide roles (e.g. HR) can be added to the same allow-list in code when present in `fw_v_users.role_code`.

## Validation

- Task must exist in the project (`fw_prj_tasks.project_id`).
- Worker must be assigned on that task: row in `fw_prj_team_members` with matching `project_id`, `task_id`, `user_id` (same model as task assignees / lead).
- `work_date` must fall in the week window **Monday–Sunday** of the schedule week’s `week_start`.

## Endpoints

### GET `/api/v1/projects/{projectId}/schedule-weeks?week_start=YYYY-MM-DD`

Any day in the week may be passed; the server resolves the Monday. Returns `schedule_week` + `entries`, or `schedule_week: null` if none.

**200 example:**

```json
{
  "error_code": 0,
  "status": "success",
  "message": "Schedule week retrieved",
  "data": {
    "schedule_week": {
      "id": 1,
      "project_id": 9,
      "week_start": "2026-03-23",
      "status": "draft",
      "published_at": null,
      "published_by": null,
      "created_at": "2026-03-29T12:00:00.000",
      "updated_at": "2026-03-29T12:00:00.000"
    },
    "entries": [
      {
        "id": 10,
        "user_id": 47,
        "task_id": 101,
        "work_date": "2026-03-24",
        "day_part": "am",
        "schedule_week_id": 1,
        "project_id": 9
      }
    ]
  }
}
```

### POST `/api/v1/projects/{projectId}/schedule-weeks`

Body: `{ "week_start": "2026-03-25" }` (normalized to Monday). Creates draft if missing. **409** if a **published** week already exists for that `(project_id, week_start)`.

**Response shape (always the same for idempotent calls):** `data.schedule_week` and `data.entries` (same fields as GET). **201** when a new draft row is inserted (`entries` is `[]`). **200** when a draft already existed — full **`entries`** from DB so the client does not need a follow-up GET.

### PUT `/api/v1/projects/{projectId}/schedule-weeks/{weekId}/entries`

Body:

```json
{
  "entries": [
    { "user_id": 47, "task_id": 101, "work_date": "2026-03-24", "day_part": "am" }
  ]
}
```

Replaces all rows for that week in a transaction. **409** if week is not `draft`, or duplicate slot in payload / DB unique violation.

### POST `/api/v1/projects/{projectId}/schedule-weeks/{weekId}/publish`

Sets `published`, `published_at`, `published_by`. Re-validates every stored entry; **400** if invalid, **409** if not draft.

### GET `/api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD`

Published weeks only. Each entry includes `schedule_week_id`, `user_id`, nested `task` (`id`, `name`, `project_id`, `status`), and **`project_name`** (from `fw_projects.prj_name`) for UI hints.

**Range:** `from` and `to` are inclusive; span must not exceed **62** calendar days (otherwise **400**).

### GET `/api/v1/users/{userId}/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD`

Same response shape and range rules as `/me/schedule`, but for the given user across **all** projects. **404** if the user id does not exist or is archived. **403** if the caller is not allowed (see RBAC above). Only **published** weeks (no `include_draft` in MVP).

## HTTP codes

| Code | Meaning |
|------|---------|
| 400 | Validation (`day_part`, task/project, assignee, dates) |
| 403 | Role |
| 404 | Project or week |
| 409 | Wrong draft/published state, duplicate slot |

## Integration test

```bash
# Optional env: DB_* from .env; required for a full run:
# SCHEDULE_TEST_PROJECT_ID, SCHEDULE_TEST_USER_ID, SCHEDULE_TEST_TASK_ID
php scripts/test-schedule-weeks-integration.php
```

OpenAPI: regenerate with `php scripts/generate-swagger.php` (tag **Schedule**).
