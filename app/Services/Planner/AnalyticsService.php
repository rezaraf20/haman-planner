<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ExecutionLog;
use App\Models\Task;
use App\Models\Goal;
use App\Models\DailyPlan;
use Illuminate\Support\Carbon;

final class AnalyticsService
{
    public function summary(Carbon $from, Carbon $to, ?int $userId = null): array
    {
        // When $userId is given every metric is restricted to that planner owner.
        $own = fn ($query) => $query->when($userId !== null, fn ($q) => $q->where('user_id', $userId));
        $tasks = $own(Task::query())->whereBetween('created_at', [$from, $to])->get();
        $completed = $tasks->where('status', 'completed')->count();
        $estimated = (int) $tasks->sum('estimated_minutes');
        $actual = (int) $tasks->sum('actual_minutes');
        $logs = $own(ExecutionLog::query())->whereBetween('started_at', [$from, $to])->get();

        $overdue = $own(Task::query())
            ->where('deadline', '<', $to)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
        $failed = $own(Task::query())
            ->whereBetween('updated_at', [$from, $to])
            ->whereNotNull('failure_reason')
            ->count();
        $scheduled = $own(Task::query())
            ->whereBetween('planned_start', [$from, $to])
            ->whereNotNull('planned_start')
            ->count();

        $variances = $tasks
            ->filter(fn (Task $task): bool => $task->completed_at !== null && $task->planned_end !== null)
            ->map(fn (Task $task): float => $task->planned_end->diffInMinutes($task->completed_at, false));

        $deepWork = (int) $logs->filter(fn ($log) => (int) $log->duration_minutes >= 45)->sum('duration_minutes');
        $velocity = $days = max(1, $from->diffInDays($to) + 1);
        $activeGoals = $own(Goal::query())->whereNotIn('status', ['completed','cancelled'])->get();
        $health = $activeGoals->groupBy(fn ($g) => $g->health ?: 'unknown')->map->count()->all();
        $planningDays = $own(DailyPlan::query())->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])->get();
        $overloadedDays = $planningDays->filter(fn ($p) => (int)$p->planned_minutes > (int)$p->available_minutes)->count();
        $failureReasons = $own(Task::query())->whereBetween('updated_at', [$from, $to])->whereNotNull('failure_reason')->selectRaw('failure_reason, count(*) as total')->groupBy('failure_reason')->orderByDesc('total')->limit(10)->pluck('total','failure_reason')->all();
        $blockers = $own(Task::query())->whereBetween('updated_at', [$from, $to])->where('status','blocked')->count() + $logs->filter(fn ($log) => filled($log->blocker))->count();

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'tasks_created' => $tasks->count(),
            'tasks_completed' => $completed,
            'completion_rate' => $tasks->count() ? round($completed / $tasks->count() * 100, 2) : 0,
            'estimated_minutes' => $estimated,
            'actual_minutes' => $actual,
            'estimation_error_percent' => $estimated ? round(($actual - $estimated) / $estimated * 100, 2) : 0,
            'execution_minutes' => (int) $logs->sum('duration_minutes'),
            'execution_sessions' => $logs->count(),
            'overdue_open_tasks' => $overdue,
            'tasks_with_failures' => $failed,
            'scheduled_tasks' => $scheduled,
            'average_schedule_variance_minutes' => $variances->count() ? round($variances->avg(), 2) : null,
            'schedule_variance_sample_size' => $variances->count(),
            'deep_work_minutes' => $deepWork,
            'velocity_completed_tasks_per_day' => round($completed / $days, 2),
            'active_goals' => $activeGoals->count(),
            'average_goal_progress' => $activeGoals->count() ? round((float)$activeGoals->avg('progress'), 2) : 0,
            'goal_health' => $health,
            'overload_rate_percent' => $planningDays->count() ? round($overloadedDays / $planningDays->count() * 100, 2) : 0,
            'failure_rate_percent' => $tasks->count() ? round($failed / $tasks->count() * 100, 2) : 0,
            'failure_reasons' => $failureReasons,
            'blockers' => $blockers,
            'priority_distribution' => $tasks->groupBy('priority')->map->count()->all(),
        ];
    }
}
