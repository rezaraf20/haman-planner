<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Review;
use Carbon\Carbon;

final class ReviewService
{
    public function generate(string $type, Carbon $from, Carbon $to, array $metrics): Review
    {
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
            'actions_json' => [],
        ]);
    }
}
