<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Domain\Planner\CapacityPlanner;
use App\Models\Task;
use Illuminate\Support\Collection;

final class DailyPlannerEngine
{
    public function __construct(private readonly CapacityPlanner $capacity) {}

    public function build(int $availableMinutes, ?Collection $tasks = null): array
    {
        $usable = $this->capacity->usableMinutes($availableMinutes);
        $tasks ??= Task::query()
            ->whereIn('status', ['inbox', 'planned', 'ready', 'deferred'])
            ->where(function ($q) {
                $q->whereNull('deadline')->orWhere('deadline', '>=', now()->subDay());
            })
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")
            ->orderByDesc('importance')
            ->get();

        $selected = [];
        $minutes = 0;

        foreach ($tasks as $task) {
            $estimate = max(1, (int) $task->estimated_minutes);
            if ($minutes + $estimate > $usable && $minutes > 0) {
                continue;
            }

            $selected[] = [
                'task_id' => $task->id,
                'title' => $task->title,
                'priority' => $task->priority,
                'estimated_minutes' => $estimate,
            ];
            $minutes += $estimate;

            if ($minutes >= $usable) break;
        }

        return [
            'available_minutes' => $availableMinutes,
            'usable_minutes' => $usable,
            'planned_minutes' => $minutes,
            'buffer_minutes' => max(0, $availableMinutes - $minutes),
            'tasks' => $selected,
        ];
    }
}
