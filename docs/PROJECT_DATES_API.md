# Project dates (date_start, date_end) — API behaviour

## Overview

Project dates are **optional (nullable)** and can be **recalculated automatically** from tasks.

## Endpoints

### POST /api/v1/projects (create)

- **date_start**, **date_end** are **not required**; they can be `null`.
- If omitted or sent as `null`, the project is created with `date_start` and `date_end` set to `null`.
- Format when provided: `YYYY-MM-DD`.

### PUT /api/v1/projects/:id (update)

- **Partial update** supported: you can send only `date_start` and/or `date_end`.
- Both fields accept `null` to clear the value.
- Other project fields are left unchanged if not sent.

## Automatic recalculation from tasks

After task create/update/delete, the backend recalculates the project’s dates from its tasks:

- **date_start** = `MIN(start_planned)` over all tasks of the project (only non-null `start_planned`).
- **date_end** = `MAX(end_planned)` over all tasks of the project (only non-null `end_planned`).

Recalculation runs after:

- **POST** /api/v1/projects/:id/tasks (create task)
- **PUT** /api/v1/projects/:id/tasks/:taskId (update task)
- **DELETE** /api/v1/projects/:id/tasks/:taskId (delete task)

If there are no tasks or all task dates are null, project `date_start` and `date_end` become `null`.

## Authoritative spec

The **authoritative** request/response schema is the **OpenAPI (Swagger)** spec:

- Generated file: `public/swagger.json`
- Regenerate after controller changes: `php scripts/generate-swagger.php`
- UI: e.g. `GET /docs` (depending on your server setup)
