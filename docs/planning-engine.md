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