<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ExecutionLog;
use App\Models\Task;
use Illuminate\Support\Carbon;

final class AnalyticsService
{
    public function summary(Carbon $from, Carbon $to): array
    {
        $tasks = Task::query()->whereBetween('created_at', [$from, $to])->get();
        $completed = $tasks->where('status', 'completed')->count();
        $estimated = (int) $tasks->sum('estimated_minutes');
        $actual = (int) $tasks->sum('actual_minutes');
        $logs = ExecutionLog::query()->whereBetween('started_at', [$from, $to])->get();

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'tasks_created' => $tasks->count(),
            'tasks_completed' => $completed,
            'completion_rate' => $tasks->count() ? round($completed / $tasks->count() * 100, 2) : 0,
            'estimated_minutes' => $estimated,
            'actual_minutes' => $actual,
            'estimation_error_percent' => $estimated ? round(($actual - $estimated) / $estimated * 100, 2) : 0,
            'execution_minutes' => (int) $logs->sum('duration'),
            'execution_sessions' => $logs->count(),
        ];
    }
}
