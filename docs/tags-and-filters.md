# Tags, smart lists and saved filters

## Tags
- Table `tags` (unique per user and name), pivot `task_tag`. Owned through `BelongsToPlannerUser`; another user's tag ids are rejected with 422.
- Tasks accept `tag_ids` (existing tags) and `new_tags` (names; reused case-insensitively, created otherwise). Deleting a tag never deletes tasks.
- API: `GET/POST /api/tags`, `PUT/DELETE /api/tags/{id}`. `GET /api/tags` includes `open_tasks`.

## Filter definitions
A filter is a JSON object validated by `App\Services\Planner\TaskFilter` (unknown keys → 422, referenced ids must belong to the user) and applied with the query builder — never raw SQL.

Keys: `state` (open|closed|all), `due` (today|tomorrow|overdue|week|none), `priority` [p0..p3], `tags` + `tag_mode` (any|all), `project_id`, `goal_id`, `area_id`, `no_project`, `min_minutes`, `max_minutes`, `min_importance`, `blocked`, `text`.

Smart lists (`GET /api/tasks?smart=…`): today, tomorrow, overdue, upcoming (7 days), no_deadline, high_priority (p0/p1), high_contribution (importance ≥ 70), quick (estimate 1–30 min; tasks without an estimate are excluded), deep_work (≥ 60 min), blocked (status blocked or waiting on an unfinished dependency), no_project.

Custom: `GET /api/tasks?filter=<json>` or `?tag=<id>`.

## Saved filters
`GET /api/filters` (saved + preset names), `POST /api/filters` {name, definition}, `PUT/DELETE /api/filters/{id}`.

## Text → filter (AI)
`POST /api/filters/parse` {text}. Needs the `ai_planner` feature and one `ai_requests` unit (refunded if the provider fails). The model only sees the user's own tag/project/goal ids and titles; its answer is cut to the known keys and validated like a hand-made filter (invented ids → 422). The result is shown as a preview; nothing is executed or saved without the user. When no AI provider is configured the endpoint answers 503 and the UI suggests the smart lists.
