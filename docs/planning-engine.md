# Planning Engine

**Author:** Reza Rafiei

## Purpose
Convert goals and constraints into an executable plan while respecting capacity, deadlines, dependencies and priorities.

## Inputs
- active goals and health
- active projects and milestones
- task priorities
- deadlines
- dependencies
- estimated effort
- available time
- energy/focus constraints
- existing schedule blocks
- historical estimation accuracy

## Priority signals
1. Strategic importance
2. Deadline urgency
3. Contribution to goal
4. Dependency impact
5. Overdue status
6. Effort and available capacity
7. Manual override

## Capacity algorithm
1. Determine usable working time.
2. Reserve buffer (default 20%).
3. Exclude existing commitments.
4. Rank eligible work.
5. Fill capacity without violating dependencies.
6. Leave unresolved overflow visible rather than silently overbooking.
7. Record planning decisions.

## Estimation learning
Track estimated vs actual minutes and calculate an estimation factor by task/project/category when sufficient historical data exists. Future estimates may be adjusted using that factor, but the raw estimate must remain available.

## Rescheduling
Rescheduling must preserve the original deadline and record schedule changes. Repeated deferral is a signal for review, not a reason to silently lower priority.

## Output
A daily plan containing selected tasks, planned minutes, schedule blocks, expected completion and explicit overflow.
## Recurring tasks
`recurring_tasks` holds the rule (daily / weekly on chosen weekdays / monthly / yearly, every N, Gregorian or
Jalali months, optional end date or count) and a task template. `RecurrenceService` creates real `tasks` rows for
occurrences up to `PLANNER_RECURRENCE_HORIZON_DAYS` ahead (hourly `planner:recurring`), unique per
(series, date). Skipping an occurrence or editing "this and future" never touches completed or edited occurrences.

## Time blocking and capacity
`schedule_blocks` (kind work/focus/break/meeting, fixed or movable) and scheduled tasks form the day. Capacity =
the user's working hours on working days minus breaks, the planning buffer (default 20%) and, optionally, imported
calendar busy time. Over-capacity days and overlapping items are reported, never silently changed.

## Smart rescheduling and Haman AI
`SmartReschedulingService` is deterministic: it ranks work with `TaskRanker` (importance, deadline pressure,
overdue, dependencies — every score has a reason) and places it into free slots respecting deadlines, breaks and
fixed blocks. Modes: plan today, plan this/next week, fix an overloaded plan, "what should I do now?". The result is a
`plan_proposal` of explicit actions (schedule, move, defer, split, at risk, add buffer). If an AI provider is
configured, it may only add a summary and notes to those actions; unknown task IDs are dropped. Nothing is
applied until the user confirms; applying re-validates each action and logs it with actor "ai".

## Insights and weekly review
Plan-vs-actual uses completed tasks with both estimate and logged time (minimum `PLANNER_INSIGHTS_MIN_SAMPLES`),
failure patterns use recorded failure reasons; below the minimum the app says there is not enough data. The weekly
review stores metrics (completed, missed, rescheduled, planned vs actual, blockers, projects needing attention)
and rule-based recommendations; an optional AI summary is added on top and never replaces the numbers.
