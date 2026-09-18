<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DependencyService
{
    public function add(Task $task, Task $dependency, string $type = 'requires'): TaskDependency
    {
        if ($task->id === $dependency->id) {
            throw new RuntimeException('A task cannot depend on itself.');
        }

        if ($this->wouldCreateCycle($task, $dependency)) {
            throw new RuntimeException('Dependency would create a cycle.');
        }

        return TaskDependency::firstOrCreate([
            'task_id' => $task->id,
            'depends_on_task_id' => $dependency->id,
            'type' => $type,
        ]);
    }

    public function canStart(Task $task): bool
    {
        $blocked = $task->dependencies()
            ->where('type', 'requires')
            ->whereHas('dependsOn', fn ($q) => $q->where('status', '!=', 'completed'))
            ->exists();

        return ! $blocked && ! in_array($task->status, ['completed','cancelled'], true);
    }

    private function wouldCreateCycle(Task $task, Task $dependency): bool
    {
        $seen = [];
        $stack = [$dependency->id];

        while ($stack) {
            $id = array_pop($stack);
            if ($id === $task->id) return true;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $stack = array_merge(
                $stack,
                TaskDependency::where('task_id', $id)->pluck('depends_on_task_id')->all()
            );
        }

        return false;
    }
}
