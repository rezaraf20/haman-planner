<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\PlanProposal;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\Analytics\ProductEvents;
use App\Support\ActivityContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies the actions a user selected from a proposal. Each action is re-validated against the
 * current data (ownership, still open, still where the proposal saw it, no new conflict);
 * anything that changed is skipped with a reason instead of being forced. Changes are logged
 * as AI actions (confirmed by the user) in the activity timeline.
 */
final class PlanApplier
{
    public function __construct(private readonly SchedulingService $scheduling, private readonly ActivityLogger $activity) {}

    /** @param list<string> $keys @return array<string,array{status:string,reason?:string}> */
    public function apply(User $user, PlanProposal $proposal, array $keys): array
    {
        $results = [];
        $byKey = collect($proposal->actions)->keyBy('key');
        ActivityContext::asAi(function () use ($user, $proposal, $keys, $byKey, &$results): void {
            DB::transaction(function () use ($user, $proposal, $keys, $byKey, &$results): void {
                foreach (array_values(array_unique($keys)) as $key) {
                    $a = $byKey->get($key);
                    $results[$key] = $a === null ? ['status' => 'skipped', 'reason' => 'unknown_action'] : $this->one($user, $a);
                }
                $proposal->update(['status' => 'applied', 'applied_at' => now(), 'applied_actions' => $results]);
            });
        });
        if (collect($results)->contains(fn ($r) => $r['status'] === 'applied')) {
            ProductEvents::record($user, ProductEvents::FIRST_PLAN_APPLIED, ['kind' => $proposal->kind], true);
        }
        return $results;
    }

    private function one(User $user, array $a): array
    {
        $task = $a['task_id'] ? Task::query()->ownedBy($user->id)->find($a['task_id']) : null;
        if ($a['task_id'] && (!$task || in_array($task->status, ['completed', 'cancelled'], true))) {
            return ['status' => 'skipped', 'reason' => 'task_changed'];
        }
        return match ($a['type']) {
            'schedule', 'move' => $this->place($user, $task, $a),
            'defer' => $this->defer($task),
            'split' => $this->split($task, (int) ($a['parts'] ?? 2), (int) $a['minutes']),
            'add_buffer' => $this->buffer($user, $a),
            default => ['status' => 'skipped', 'reason' => 'informational'],
        };
    }

    private function place(User $user, Task $task, array $a): array
    {
        $start = CarbonImmutable::parse($a['to']['starts_at']);
        $end = CarbonImmutable::parse($a['to']['ends_at']);
        if ($a['type'] === 'move' && $a['from'] && $task->planned_start
            && CarbonImmutable::instance($task->planned_start)->getTimestamp() !== CarbonImmutable::parse($a['from']['starts_at'])->getTimestamp()) {
            return ['status' => 'skipped', 'reason' => 'task_changed'];
        }
        if ($this->scheduling->conflictsFor($user, $start, $end, 'task', $task->id, $task->id) !== []) {
            return ['status' => 'skipped', 'reason' => 'conflict'];
        }
        $before = ['planned_start' => $task->planned_start?->toIso8601String(), 'planned_end' => $task->planned_end?->toIso8601String()];
        $block = ScheduleBlock::query()->ownedBy($user->id)->where('task_id', $task->id)->where('starts_at', '>=', \App\Support\LocalDate::db(CarbonImmutable::now()->startOfDay()))->orderBy('starts_at')->first();
        if ($block) {
            $block->update(['starts_at' => $start, 'ends_at' => $end]);
        } else {
            ScheduleBlock::create(['user_id' => $user->id, 'task_id' => $task->id, 'kind' => 'task', 'starts_at' => $start, 'ends_at' => $end, 'source' => 'haman_ai', 'status' => 'planned']);
        }
        $task->planned_start = $start;
        $task->planned_end = $end;
        if (in_array($task->status, ['inbox', 'deferred'], true)) $task->status = 'planned';
        if ($task->isOccurrence() && $task->recurrence_exception === null && $task->isDirty(['planned_start'])) $task->recurrence_exception = 'moved';
        $task->save();
        $this->activity->log($before['planned_start'] ? 'rescheduled' : 'scheduled', Task::class, $task->id, $before,
            ['planned_start' => $start->toIso8601String(), 'planned_end' => $end->toIso8601String(), 'source' => 'plan_proposal']);
        return ['status' => 'applied'];
    }

    private function defer(Task $task): array
    {
        $before = ['planned_start' => $task->planned_start?->toIso8601String(), 'status' => $task->status];
        ScheduleBlock::query()->where('task_id', $task->id)->where('starts_at', '>=', \App\Support\LocalDate::db(CarbonImmutable::now()))->get()->each->delete();
        $task->update(['planned_start' => null, 'planned_end' => null, 'status' => 'deferred']);
        $this->activity->log('deferred', Task::class, $task->id, $before, ['status' => 'deferred', 'source' => 'plan_proposal']);
        return ['status' => 'applied'];
    }

    private function split(Task $task, int $parts, int $minutes): array
    {
        $parts = max(2, min(6, $parts));
        $each = (int) (ceil($minutes / $parts / 5) * 5);
        for ($i = 1; $i <= $parts; $i++) {
            $child = Task::create([
                'user_id' => $task->user_id, 'parent_task_id' => $task->id, 'title' => $task->title.' ('.$i.'/'.$parts.')',
                'goal_id' => $task->goal_id, 'project_id' => $task->project_id, 'milestone_id' => $task->milestone_id, 'area_id' => $task->area_id,
                'priority' => $task->priority, 'importance' => $task->importance, 'estimated_minutes' => $each, 'deadline' => $task->deadline, 'status' => 'ready',
            ]);
            $this->activity->log('created', Task::class, $child->id, null, ['title' => $child->title, 'parent_task_id' => $task->id, 'source' => 'plan_proposal']);
        }
        return ['status' => 'applied'];
    }

    private function buffer(User $user, array $a): array
    {
        $start = CarbonImmutable::parse($a['to']['starts_at']);
        $end = CarbonImmutable::parse($a['to']['ends_at']);
        $block = ScheduleBlock::create(['user_id' => $user->id, 'kind' => 'buffer', 'title' => __('planning.buffer_title', [], $user->preferredLocale()),
            'starts_at' => $start, 'ends_at' => $end, 'source' => 'haman_ai', 'status' => 'planned']);
        $this->activity->log('buffer_added', ScheduleBlock::class, $block->id, null, ['starts_at' => $start->toIso8601String(), 'source' => 'plan_proposal']);
        return ['status' => 'applied'];
    }
}
