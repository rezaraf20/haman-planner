# Database Design

**Author:** Reza Rafiei

## Core tables

### areas
id, name, type, description, status, color, sort_order, timestamps

### goals
id, area_id, title, description, status, start_date, target_date, importance, weight, progress, health, success_criteria, timestamps

### projects
id, goal_id, title, description, status, importance, weight, progress, health, estimated_minutes, actual_minutes, start_date, target_date, timestamps

### milestones
id, project_id, title, status, weight, progress, target_date, completed_at, timestamps

### tasks
id, area_id, goal_id, project_id, milestone_id, parent_task_id, title, description, status, priority, importance, weight, progress, estimated_minutes, actual_minutes, planned_start, planned_end, deadline, energy_level, focus_level, failure_reason_id, timestamps

### task_dependencies
id, task_id, depends_on_task_id, type, timestamps

### daily_plans
id, plan_date, available_minutes, planned_minutes, completed_minutes, buffer_minutes, focus_level, energy_level, notes, timestamps

### schedule_blocks
id, task_id, starts_at, ends_at, source, status, timestamps

### execution_logs
id, task_id, started_at, ended_at, duration_minutes, focus_level, energy_level, result, blocker, notes, timestamps

### failure_reasons
id, code, name, preventable, severity, timestamps

### reminders
id, task_id, type, scheduled_at, status, payload, timestamps

### reviews
id, type, period_start, period_end, summary, metrics_json, actions_json, timestamps

### notes
id, area_id, goal_id, project_id, task_id, title, content, timestamps

### decisions
id, area_id, title, decision, rationale, decided_at, timestamps

### ai_interactions
id, provider, model, intent, input_hash, input_payload, output_payload, confidence, status, created_at

### activity_logs
id, actor_type, actor_id, action, entity_type, entity_id, before_json, after_json, created_at

## Integrity
Foreign keys, indexes on dates/status/relationships, soft deletion where appropriate, immutable audit records, UTC timestamps and explicit user timezone handling are required.