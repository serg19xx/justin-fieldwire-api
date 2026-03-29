# Team Member Roles

This document describes how `fw_prj_team_members.role_in_project` is used in the project and how it maps to task API fields.

## Why this exists

Task assignment fields were moved from `fw_prj_tasks` to `fw_prj_team_members`:

- `task_lead_id` and `team_members` were removed from `fw_prj_tasks`
- assignments are now stored in `fw_prj_team_members` with `task_id`

Main goal:

- use one assignment table for project-level membership and task-level assignment
- allow one user to be assigned to multiple tasks

Related migration scripts:

- `scripts/remove-task-lead-and-team-members-from-tasks.sql`
- `scripts/add-task-id-to-team-members.sql`
- `scripts/fix-team-members-unique-constraint.sql`
- `scripts/add-invited-people-field.sql`

## Roles in `fw_prj_team_members.role_in_project`

- `task_lead` - task lead (foreman/supervisor role in task context)
- `member` - regular task assignee (executor)
- `invited` - invited person (used with invited flow; for milestones, external guests are usually stored in `invited_people`)

## How API maps to DB

Task API fields:

- `task_lead_id` -> record in `fw_prj_team_members` with `role_in_project = 'task_lead'`
- `team_members[]` -> one record per user with `role_in_project = 'member'`

Quick mapping table:

| API field / meaning | DB row in `fw_prj_team_members` | Used on read |
|---|---|---|
| `task_lead_id` (task lead user) | `task_id = <task>`, `user_id = task_lead_id`, `role_in_project = 'task_lead'` | returned as `task_lead_id` |
| `team_members[]` (regular assignees) | for each user: `task_id = <task>`, `user_id = <member_id>`, `role_in_project = 'member'` | collected into `team_members[]` |
| milestone invitees | usually stored in `invited_people` JSON on the `task_lead` row | returned as `invited_people[]` for milestone |

Controller behavior (`TaskController`):

- on create/update normal task:
  - inserts/updates one `task_lead` row
  - inserts `member` rows for all `team_members`
- on read:
  - lead-like roles are mapped to `task_lead_id`
  - non-lead assignees are returned as `team_members`

## Milestone behavior

Milestone task uses a special storage mode:

- one main row with `role_in_project = 'task_lead'`
- external invitees are stored in `invited_people` JSON in that row
- `team_members` is not the primary model for milestone assignees

## Practical API examples

### 1) Create normal task

Request payload:

```json
{
  "name": "Install drywall",
  "start_planned": "2026-03-25",
  "task_lead_id": 45,
  "team_members": [50, 53, 49]
}
```

Expected DB rows:

- `(task_id = T, user_id = 45, role_in_project = 'task_lead')`
- `(task_id = T, user_id = 50, role_in_project = 'member')`
- `(task_id = T, user_id = 53, role_in_project = 'member')`
- `(task_id = T, user_id = 49, role_in_project = 'member')`

### 2) Update normal task assignees

Request payload:

```json
{
  "task_lead_id": 45,
  "team_members": [46, 49]
}
```

Result after update (logical target state for task `T`):

- one lead row for user `45`
- member rows for users `46` and `49`
- users removed from `team_members` should not remain in active assignment set for that task

### 3) Create or update milestone with invitees

Request payload:

```json
{
  "name": "Final inspection",
  "start_planned": "2026-03-30",
  "milestone": "inspection",
  "task_lead_id": 45,
  "invited_people": [
    { "name": "City Inspector", "email": "inspector@example.com" },
    { "name": "Client Rep", "company": "ACME" }
  ]
}
```

Expected DB shape:

- one main row `(task_id = M, user_id = 45, role_in_project = 'task_lead', invited_people = '[...]')`
- no requirement to store milestone guests as separate `member` rows

## Quick SQL checks

Check all assignees for one task:

```sql
SELECT id, project_id, task_id, user_id, role_in_project, assigned_at, invited_people
FROM fw_prj_team_members
WHERE task_id = :task_id
ORDER BY id;
```

Check who is lead vs members:

```sql
SELECT
  SUM(CASE WHEN role_in_project = 'task_lead' THEN 1 ELSE 0 END) AS lead_rows,
  SUM(CASE WHEN role_in_project = 'member' THEN 1 ELSE 0 END) AS member_rows,
  SUM(CASE WHEN role_in_project = 'invited' THEN 1 ELSE 0 END) AS invited_rows
FROM fw_prj_team_members
WHERE task_id = :task_id;
```

Find tasks where a user is a regular assignee:

```sql
SELECT task_id, project_id, assigned_at
FROM fw_prj_team_members
WHERE user_id = :user_id
  AND role_in_project = 'member'
ORDER BY assigned_at DESC;
```

## Notes

- `role_in_project` is task-context role when `task_id` is set.
- Project-level team records may also exist in the same table (usually with `task_id IS NULL`), so always include `task_id` in task assignment queries.

## Project team JSON: roster vs task team

- **`GET /api/v1/projects/{id}/tasks/{taskId}/team`** — one object per `fw_prj_team_members` row: `id` is the **membership row id**, `user_id` is the user (or null for some invited rows), `role_in_project`, `assigned_at`.
- **`GET /api/v1/projects/{id}/team`** — paginated roster; legacy names `team_member_id`, `project_role`, `added_at` remain. The same payload also includes **aliases** for SPA parity: `role_in_project`, `assigned_at`, `user_id` (same as user `id`). The **membership row id** is `team_member_id` (use it for `PUT/DELETE .../team/{team_member_id}`).

## Project `sys_status` (API)

- Stored as DB enum: `Draft`, `Active`, `Closing`, `Suspended`, `Done` (PascalCase).
- JSON field name: `sys_status`. If the client expects lowercase lifecycle keys, normalize in the SPA (see `readSysStatusFromApiRow` + mapping helpers).
