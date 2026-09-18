<?php
declare(strict_types=1);
namespace App\Services\Planner;
use App\Domain\Planner\PriorityCalculator;
use App\Domain\Planner\ProgressCalculator;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final class PlannerService
{
    public function __construct(
        private readonly ProgressCalculator $progress,
        private readonly PriorityCalculator $priority,
    ) {}

    public function createTask(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $task = Task::create($data);
            $this->recalculatePriority($task);
            return $task->refresh();
        });
    }

    public function complete(Task $task): Task
    {
        $task->update(['status'=>'completed','progress'=>100]);
        return $task->refresh();
    }

    public function recalculatePriority(Task $task): Task
    {
        $task->priority = $this->priority->calculate(
            (int)$task->importance,
            (int)optional($task->goal)->importance,
            $task->deadline ? max(0, 100 - (now()->diffInHours($task->deadline, false) / 24 * 5)) : 0,
            (float)$task->progress,
            $task->children()->where('status','blocked')->count() > 0 ? 100 : 0,
            $task->deadline?->isPast() ?? false,
            (int)$task->estimated_minutes
        );
        $task->save();
        return $task;
    }
}