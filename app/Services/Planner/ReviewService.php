<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Review;
use Carbon\Carbon;

final class ReviewService
{
    public function generate(string $type, Carbon $from, Carbon $to, array $metrics): Review
    {
        $actions = [];

        $completionRate = (float) ($metrics['completion_rate'] ?? 0);
        $estimationError = abs((float) ($metrics['estimation_error_percent'] ?? 0));
        $overdue = (int) ($metrics['overdue_open_tasks'] ?? 0);
        $failures = (int) ($metrics['tasks_with_failures'] ?? 0);
        $variance = $metrics['average_schedule_variance_minutes'] ?? null;

        if ($completionRate < 70) {
            $actions[] = [
                'type' => 'planning',
                'priority' => 'high',
                'action' => 'Reduce planned workload and review task priorities for the next period.',
            ];
        }

        if ($estimationError >= 25) {
            $actions[] = [
                'type' => 'estimation',
                'priority' => 'medium',
                'action' => 'Review recent estimates and use actual execution time to recalibrate future estimates.',
            ];
        }

        if ($overdue > 0) {
            $actions[] = [
                'type' => 'deadlines',
                'priority' => 'high',
                'action' => 'Review overdue open tasks and explicitly reschedule, defer, or cancel them.',
            ];
        }

        if ($failures > 0) {
            $actions[] = [
                'type' => 'failures',
                'priority' => 'medium',
                'action' => 'Review repeated failure reasons and define one preventive action for the next period.',
            ];
        }

        if ($variance !== null && abs((float) $variance) >= 30) {
            $actions[] = [
                'type' => 'scheduling',
                'priority' => 'medium',
                'action' => 'Adjust schedule blocks using observed completion variance.',
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'type' => 'maintenance',
                'priority' => 'low',
                'action' => 'Keep the current planning approach and continue collecting execution data.',
            ];
        }

        $summary = sprintf(
            '%s review: %s tasks completed, %s%% completion rate, %s execution minutes.',
            strtoupper($type),
            $metrics['tasks_completed'] ?? 0,
            $metrics['completion_rate'] ?? 0,
            $metrics['execution_minutes'] ?? 0
        );

        return Review::create([
            'type' => $type,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'summary' => $summary,
            'metrics_json' => $metrics,
            'actions_json' => $actions,
        ]);
    }
}
