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
        private readonly ActivityLogger $activity,
    ) {}

    public function createTask(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $task = Task::create($data);
            $this->recalculatePriority($task);
            $this->activity->log('created', Task::class, $task->id, null, $task->toArray());
            return $task->refresh();
        });
    }

    public function complete(Task $task): Task
    {
        $before = $task->toArray();
        $task->update(['status' => 'completed', 'progress' => 100]);
        $this->activity->log('completed', Task::class, $task->id, $before, $task->fresh()->toArray());
        return $task->refresh();
    }

    public function defer(Task $task): Task
    {
        $before = $task->toArray();
        $task->update(['status' => 'deferred']);
        $this->activity->log('deferred', Task::class, $task->id, $before, $task->fresh()->toArray());

        return $task->refresh();
    }

    public function logTime(Task $task, array $data): \App\Models\ExecutionLog
    {
        $start = isset($data['started_at']) ? \Carbon\Carbon::parse($data['started_at']) : now();
        $end = isset($data['ended_at']) ? \Carbon\Carbon::parse($data['ended_at']) : null;
        $duration = isset($data['duration_minutes'])
            ? max(0, (int) $data['duration_minutes'])
            : ($end ? max(0, $start->diffInMinutes($end)) : 0);

        return DB::transaction(function () use ($task, $data, $start, $end, $duration) {
            $log = \App\Models\ExecutionLog::create([
                'task_id' => $task->id,
                'started_at' => $start,
                'ended_at' => $end,
                'duration_minutes' => $duration,
                'focus_level' => isset($data['focus_level']) ? max(0, min(100, (int) $data['focus_level'])) : null,
                'energy_level' => isset($data['energy_level']) ? max(0, min(100, (int) $data['energy_level'])) : null,
                'result' => $data['result'] ?? null,
                'blocker' => $data['blocker'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $task->increment('actual_minutes', $duration);
            $this->activity->log('time_logged', Task::class, $task->id, null, $log->toArray());
            return $log->refresh();
        });
    }

    public function logFailure(Task $task, array $data): \App\Models\ActivityLog
    {
        $before = $task->toArray();
        $task->update(['failure_reason' => $data['reason'] ?? $data['failure_reason'] ?? 'other']);
        $this->activity->log('failure_logged', Task::class, $task->id, $before, $task->fresh()->toArray());
        return $this->activity->latestFor(Task::class, $task->id);
    }

    public function logBlocker(Task $task, array $data): Task
    {
        $before = $task->toArray();
        $task->update(['failure_reason' => $data['blocker'] ?? $data['reason'] ?? 'blocked', 'status' => 'blocked']);
        $this->activity->log('blocker_logged', Task::class, $task->id, $before, $task->fresh()->toArray());
        return $task->refresh();
    }

    public function recalculatePriority(Task $task): Task
    {
        $task->priority = $this->priority->calculate(
            (int) $task->importance,
            (int) optional($task->goal)->importance,
            $task->deadline ? max(0, 100 - (now()->diffInHours($task->deadline, false) / 24 * 5)) : 0,
            (float) $task->progress,
            $task->children()->where('status', 'blocked')->count() > 0 ? 100 : 0,
            $task->deadline?->isPast() ?? false,
            (int) $task->estimated_minutes
        );
        $task->save();

        return $task;
    }
}
