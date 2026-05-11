# Weekly schedule API (`schedule-weeks`)

## Database

- Run `scripts/create-schedule-weeks-tables.sql` (and `scripts/add-schedule-week-publish-snapshots.sql` on existing DBs without the snapshot table).
- **`fw_schedule_weeks`**: one row per `(project_id, week_start)` where `week_start` is the **Monday** (ISO) of the week. `status`: `draft` | `published`.
- **`fw_worker_task_schedule_snapshots`**: copy of live slots taken **on each publish**. **`GET /me/schedule`** (and **`GET /users/{id}/schedule`**) read from here so workers still see the **last published** plan after **`reopen-as-draft`** (week row returns to `draft` and `published_at` / `published_by` are cleared). The next **publish** replaces the snapshot from current draft rows.
- **`fw_worker_task_schedules`**: slots for a worker on a **calendar** `work_date`, with `day_part`:
  - `am` — morning
  - `pm` — afternoon
  - `full` — full day
- **`assignment_note`**: optional `VARCHAR(2000)` on each slot; set via **PUT …/entries**, returned on **GET week** and on **`/me/schedule`** / **`/users/{id}/schedule`** (published rows).
- **Foreign keys**: week and project CASCADE with parent; task row uses `ON DELETE CASCADE` on `fw_prj_tasks` (schedule lines are removed if the task is deleted).
- **Uniqueness**: `(schedule_week_id, user_id, work_date, day_part)` — at most one slot per user/date/part in a given week document.

## RBAC

- **Manage** (create draft, replace entries, publish, **reopen-as-draft**): global roles `admin`, `project_manager`, or user is `fw_projects.prj_manager` for the project.
- **View project week** (`GET .../schedule-weeks`): managers as above, or any user listed in `fw_prj_team_members` for that project.
- **Own schedule** (`GET /api/v1/me/schedule`): any authenticated user; rows come from the **last published snapshot** per week (see snapshots table above), not from live draft rows.
- **User schedule (cross-project)** (`GET /api/v1/users/{userId}/schedule`): same published data shape as `/me/schedule`, for conflict checking when PM plans someone else. Allowed if the caller is the same user, or has role `admin` / `project_manager`, or is `fw_projects.prj_manager` on at least one project where the target user appears in `fw_prj_team_members`. Otherwise **403**. Optional org-wide roles (e.g. HR) can be added to the same allow-list in code when present in `fw_v_users.role_code`.

## Validation

- Task must exist in the project (`fw_prj_tasks.project_id`).
- **Task roster (assignee)** matches task API semantics: a user counts as on the task if there is any row in **`fw_prj_team_members`** with the same **`project_id`**, **`task_id`**, and **`user_id`** (covers **task lead** and **members**; same source as **`task_lead_id` / `team_members`** on PATCH task). Legacy **`assignees`** / **`fw_prj_task_assignees`** are not used by this API if unused elsewhere.
- **`PUT …/entries` auto-roster:** inside **one transaction**, before writing slots, the server ensures every distinct **`(user_id, task_id)`** from the payload is on the task roster. If the user is **not** yet on the task but **is** on the project roster (`fw_prj_team_members` for that `project_id`, any `task_id` including `NULL`), the server **inserts** a **member** row the same way as successful task create/PATCH (`role_in_project = 'member'`, with **`ON DUPLICATE KEY UPDATE`** where applicable). **Milestone** tasks do **not** get extra member rows (task model keeps a single lead row); scheduling someone who is not already on that roster returns **400** with an explicit message. If the user is **not** on the project roster, or is archived / missing, the server returns **400** with **`cannot add user … to task …`** (no silent partial success).
- `work_date` must fall in the week window **Monday–Sunday** of the schedule week’s `week_start` (ISO week anchored on stored Monday).
- Validation errors for **`entries[]`** include **`entries[i]`** plus **`user_id`**, **`task_id`**, and **`work_date`** where those fields are present for easier PM debugging.

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
        "project_id": 9,
        "assignment_note": null
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
    { "user_id": 47, "task_id": 101, "work_date": "2026-03-24", "day_part": "am", "assignment_note": "Bring ladder" }
  ]
}
```

Replaces all rows for that week in **one transaction** (roster ensures, then delete-all for the week, then insert). **409** if week is not `draft`, or duplicate **`(user_id, work_date, day_part)`** in the payload / DB unique violation. **400** if any entry fails validation or roster rules (see **Validation** above).

### POST `/api/v1/projects/{projectId}/schedule-weeks/{weekId}/publish`

Sets `published`, `published_at`, `published_by`, and **replaces** `fw_worker_task_schedule_snapshots` for that week from current live rows. Re-validates every stored entry; **400** if invalid, **409** if not draft.

### POST `/api/v1/projects/{projectId}/schedule-weeks/{weekId}/reopen-as-draft`

Body: empty or `{}`. Only if the week is **`published`**: sets the **same** row to **`draft`**, clears **`published_at`** and **`published_by`**. Live `fw_worker_task_schedules` rows are **unchanged**. **200** response shape matches **GET** week: `schedule_week` + `entries` (live draft data).

| Code | When |
|------|------|
| **400** | Week is already `draft` (or not published) |
| **403** | Caller cannot manage schedule for the project |
| **404** | Project or week not found |

Workers keep seeing the **previous publish** via **`GET /me/schedule`** until the PM **publishes** again (snapshot is not cleared on reopen).

### GET `/api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD`

Uses **publish snapshots** (not live rows when the week is draft-after-reopen). Each entry includes:

- **`id`** — **same numeric value as `fw_worker_task_schedules.id`** for that slot when the snapshot row stores `worker_task_schedule_id` (after migration + publish). Use this (or **`worker_task_schedule_id`** when non-null) for **`…/schedule-entries/{id}/messages`**. If `worker_task_schedule_id` is still null on old snapshot rows until republish, **`id`** falls back to the internal snapshot PK — **re-publish** the week to fix.
- **`worker_task_schedule_id`** — explicit copy of **`fw_worker_task_schedules.id`** at publish time, or `null` on legacy snapshot rows.
- **`schedule_week_id`** — FK to **`fw_schedule_weeks.id`** (different from slot id).
- **`assignment_note`**, nested **`task`** (`id`, `name`, `project_id`, `status`, **`address`**), **`project_name`**.

**Range:** `from` and `to` are inclusive; span must not exceed **62** calendar days (otherwise **400**).

**GET/PUT** `…/schedule-weeks`: each **`entries[]`** item includes **`id`** and **`worker_task_schedule_id`**, both equal to **`fw_worker_task_schedules.id`** (PK of the slot row).

### Schedule entry messages (`…/schedule-entries/{id}/messages` or `…/worker-task-schedules/{id}/messages`)

- **`{id}`** in the path must be **`fw_worker_task_schedules.id`**, the same value as **`entries[].id`** from **`GET …/schedule-weeks`** for that slot.
- **GET** `?channel=foreman|pm`: loads rows where **`worker_task_schedule_id`** = that PK, **`channel`** matches, **`deleted_at IS NULL`**. There is **no** filter on **`author_user_id`** — sender and assignee (or anyone else allowed to view the slot/project) get the **same** message list if both pass RBAC.
- **POST** body **`channel`**: required, **`foreman`** or **`pm`** (DB **`NOT NULL`**). **`worker_task_schedule_id`** stored = the same slot PK as in the path / week **`entries[].id`**.
- **POST body must not** include **`worker_task_schedule_id`**, **`schedule_entry_id`**, **`entry_id`**, or **`slot_id`** — the slot is **only** the path segment. Sending a second id caused historical bugs (e.g. message stored under id **43** while chat UI used **45**).
- **Backend behaviour:** `INSERT` always uses the row loaded with `WHERE fw_worker_task_schedules.id = ? AND project_id = ?` from the path. If a message appears under the wrong slot, the **POST was called with the wrong path id** (or data was inserted manually). Fix data with a one-off `UPDATE` (see `scripts/fix-schedule-message-slot-id.example.sql`).

### GET `/api/v1/users/{userId}/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD`

Same response shape and range rules as `/me/schedule`, but for the given user across **all** projects. **404** if the user id does not exist or is archived. **403** if the caller is not allowed (see RBAC above). Data source: **snapshots** (same semantics as `/me/schedule`).

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

## 6. Schedule entry messages (foreman / PM channels)

**Base path:** `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/messages`

**Alias (same behaviour):** `/api/v1/projects/{projectId}/worker-task-schedules/{scheduleEntryId}/messages`

`scheduleEntryId` is the primary key of **`fw_worker_task_schedules`** (same id as in **GET** / **PUT** week `entries[].id` — live row, not snapshot).

### 4.1 Backend: PM and worker see one thread

1. **`entries[].id`** is the real **`fw_worker_task_schedules.id`** for that row (API selects `id` only from **`fw_worker_task_schedules`**, not another table’s PK).
2. **Every message** in the chat uses **`worker_task_schedule_id`** equal to that same id. Mixing ids (e.g. 43 vs 45 for the same UI slot) **splits** the thread; the server stores whatever id is in the **URL path** for each **POST**.
3. **`GET …/messages`** returns all rows for **`(worker_task_schedule_id, channel)`** with **`deleted_at IS NULL`**, for any caller who may **view** the slot — there is **no** filter **`author_user_id = current user`**.
4. A different **`work_date`** or **`day_part`** means a different row in **`fw_worker_task_schedules`** (unique slot per `(schedule_week_id, user_id, work_date, day_part)`) ⇒ **different** threads — expected. PM and worker must both use the **same** row id for the **same** date + part (pick the matching **`entries[]`** line from **`GET …/schedule-weeks`**).

Normative literals: **`foreman`** and **`pm`** only (lowercase ASCII). Anything else → **400**.

| Method | Purpose |
|--------|---------|
| **GET** | Load one stream: required query **`channel=foreman`** or **`channel=pm`**. Optional **`limit`** (default 50, max 100), **`before_id`** (cursor: older messages with `id < before_id`). Response `data`: **`channel`**, **`messages`** (each item includes **`channel`**). |
| **POST** | Body `{ "channel": "foreman" \| "pm", "body": "…" }`. **`body`** required after trim, max **4000** characters. Response `data.message` — same object shape as list items. |

**RBAC (summary)**

- **Read:** assigned worker on the slot (`entries[].user_id`) **or** anyone who can view the project schedule (team on project, PM/admin, `prj_manager`).
- **Post `foreman`:** assigned worker, **or** task lead/supervisor/manager on that task (`fw_prj_team_members.role_in_project`), **or** global **admin**.
- **Post `pm`:** assigned worker, **or** schedule managers (`admin`, `project_manager`, `prj_manager` for the project).

**Database:** `fw_worker_task_schedule_messages` — see `scripts/create-worker-task-schedule-messages-table.sql` (and `scripts/add-channel-to-worker-task-schedule-messages.sql` if upgrading an older table without `channel`).

| Code | Meaning |
|------|---------|
| 400 | Missing/invalid `channel`, empty/overlong body, bad `limit` / `before_id` |
| 403 | Not allowed to read stream or to post to that channel |
| 404 | Project or schedule entry not found / wrong `projectId` |

## 7. Schedule entry documents (`setup` / `completed`)

**Base path:** `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents`

Documents are stored per slot (`fw_worker_task_schedules.id`) in two buckets:

- `setup` — PM instructions (photos/files before work)
- `completed` — worker results (photos/files after work)

Storage path format:

- `/uploads/schedule-slot-documents/p-{projectId}/t-{taskId}/s-{YYYY-MM-DD}/{bucket}/{storedFileName}`

Note: `am/pm/full` is intentionally not included in the path because day part is already stored in schedule slot metadata.

Database table: `fw_schedule_slot_documents` (create via `scripts/create-schedule-slot-documents-table.sql`).

### GET `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents`

Returns grouped documents:

```json
{
  "error_code": 0,
  "status": "success",
  "message": "Schedule entry documents retrieved",
  "data": {
    "setup": [],
    "completed": []
  }
}
```

### POST `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents`

`multipart/form-data`:

- `file` (required)
- `bucket` (required): `setup` | `completed`

Validation:

- `file` required
- max size: 20MB
- only `image/*` and `application/pdf` (`.pdf`) accepted
- `display_name` (optional): trimmed string, max 160 chars, no control characters; empty after trim becomes `null`

### GET `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents/{documentId}/download`

Downloads the file as attachment with original filename.

### DELETE `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents/{documentId}`

Soft-deletes one document row (`deleted_at`) and tries to remove the file from disk.

ACL:

- managers (`admin` / `project_manager` / `prj_manager`) can delete any bucket
- for `completed`, the assigned worker or uploader may also delete

Error payload format for this documents API:

```json
{ "message": "..." }
```

### PATCH `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents/{documentId}`
### PATCH `/api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/documents/{documentId}/rename`

Updates document UI name.

Request body:

```json
{ "display_name": "Marker on pharma wall" }
```

`display_name` may be `null` or empty string (stored as `null` after trim).

### Codes

| Code | Meaning |
|------|---------|
| 403 | Forbidden by project/schedule ACL |
| 404 | Project, schedule entry, or document not found |
| 422 | Validation error, or `scheduleEntryId`/`documentId` does not belong to `projectId` |
