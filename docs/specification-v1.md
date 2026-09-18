# Haman Planner — Product Specification v1

**Author:** Reza Rafiei  
**Copyright:** © 2026 Reza Rafiei  
**Status:** Foundation specification

## 1. Product definition
Haman Planner is a Personal & Business Operating System for turning objectives into executable work, measuring execution, learning from failures, and improving future planning.

Core loop:
**Area → Goal → Project → Milestone → Task → Plan → Execute → Measure → Review → Improve**

## 2. Core entities
- Area: a stable responsibility or life/business domain.
- Goal: an outcome with a target date and measurable success criteria.
- Project: a bounded body of work contributing to a goal.
- Milestone: a meaningful checkpoint inside a project.
- Task: an executable unit of work.
- Dependency: a relationship that affects execution order.
- Daily Plan: the day's capacity and intended work.
- Schedule Block: a calendar placement; separate from a deadline.
- Execution Log: what actually happened.
- Failure Reason: why planned work slipped, failed, or was blocked.
- Review: daily, weekly, or monthly reflection.
- Reminder: a time/event-based prompt.
- Note/Decision: contextual knowledge and decisions.
- AI Interaction: auditable AI input/output linked to planner data.

## 3. Non-negotiable principles
1. Goals come before task accumulation.
2. Importance and contribution weight are different concepts.
3. Deadlines and calendar scheduling are different concepts.
4. Planned effort and actual effort are both recorded.
5. Slippage has a structured reason.
6. AI analysis must be grounded in stored planner data.
7. Personal and business contexts can be separated.
8. Every important automated mutation is auditable.

## 4. Task lifecycle
Inbox → Planned → Ready → In Progress → Completed

Alternative states:
Blocked, Waiting, Deferred, Cancelled.

## 5. Progress model
A parent's progress is the weighted average of child progress:

progress = Σ(child_progress × child_weight) / Σ(child_weight)

Task MVP progress:
- 0%: not completed
- 50%: in progress
- 100%: completed

The model can later support explicit numeric progress.

## 6. Priority
Priority is calculated from multiple signals including importance, deadline urgency, goal importance, project contribution, dependency impact, overdue state and effort. The engine returns P0–P3 and supports a manual override.

## 7. Planning capacity
The planner should reserve a default 20% capacity buffer. Initial scheduling should target approximately 80% of available working capacity.

## 8. Goal health
Goal health combines progress, deadline proximity and execution velocity:
- On Track
- At Risk
- Behind
- Completed
- Paused

## 9. Analytics
The system must support completion rate, deep-work time, actual hours, planning accuracy, estimation error, schedule variance, overload rate, goal progress/velocity/health, failure rate, failure reasons, preventable failures and blockers.

## 10. AI boundaries
AI may interpret, summarize, prioritize and propose plans. It must not invent missing facts. Recommendations must expose the underlying planner data used for the recommendation.

## 11. MVP
MVP includes goals, projects, milestones, tasks, dependencies, daily plans, schedule blocks, execution logs, failure reasons, reminders, Telegram text/voice interaction, reports and grounded AI analysis.

**Owner:** Reza Rafiei