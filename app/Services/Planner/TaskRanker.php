<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Goal;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Documented, explainable task ranking used by "What should I work on?" and smart scheduling.
 * Each factor adds points and a reason; the reasons (not a bare score) are shown to the user.
 *
 *   overdue                         +40
 *   due today / within 3 days / 7   +30 / +20 / +10
 *   priority P0 / P1 / P2           +30 / +20 / +10
 *   importance (0–100)              up to +20
 *   goal importance (0–100)         up to +10
 *   unblocks other open tasks       +5 each (max +15)
 *   already in progress             +10
 *   blocked by an unfinished task   excluded (listed separately)
 */
final class TaskRanker
{
    public const OPEN = ['inbox', 'planned', 'ready', 'in_progress', 'deferred', 'waiting'];

    /** @return Collection<int,Task> open tasks with dependency info attached */
    public function candidates(User $user, int $limit = 150): Collection
    {
        $tasks = Task::query()->ownedBy($user->id)->whereIn('status', self::OPEN)
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")
            ->orderBy('deadline')->limit($limit)
            ->get(['id', 'title', 'status', 'priority', 'importance', 'weight', 'estimated_minutes', 'actual_minutes', 'deadline',
                'planned_start', 'planned_end', 'goal_id', 'project_id', 'milestone_id', 'recurring_task_id', 'occurrence_date']);
        if ($tasks->isEmpty()) {
            return $tasks;
        }
        $ids = $tasks->pluck('id')->all();
        $deps = TaskDependency::query()->ownedBy($user->id)->where('type', 'requires')
            ->where(fn ($q) => $q->whereIn('task_id', $ids)->orWhereIn('depends_on_task_id', $ids))
            ->get(['task_id', 'depends_on_task_id']);
        $depTargets = Task::query()->ownedBy($user->id)->whereIn('id', $deps->pluck('depends_on_task_id')->unique()->all())->pluck('status', 'id');
        $goals = Goal::query()->ownedBy($user->id)->whereIn('id', $tasks->pluck('goal_id')->filter()->unique()->all())->get(['id', 'title', 'importance'])->keyBy('id');

        foreach ($tasks as $t) {
            $blockers = $deps->where('task_id', $t->id)->filter(fn ($d) => ($depTargets[$d->depends_on_task_id] ?? 'completed') !== 'completed');
            $t->setAttribute('blocked_by', $blockers->pluck('depends_on_task_id')->values()->all());
            $t->setAttribute('unblocks', $deps->where('depends_on_task_id', $t->id)->pluck('task_id')->unique()->filter(fn ($id) => in_array($id, $ids, true))->count());
            $g = $t->goal_id ? $goals->get($t->goal_id) : null;
            $t->setAttribute('goal_title', $g?->title);
            $t->setAttribute('goal_importance', (int) ($g?->importance ?? 0));
        }
        return $tasks;
    }

    /** @return array{score:int,reasons:list<array{code:string,params:array,points:int}>} */
    public function score(Task $t, CarbonImmutable $now, string $tz): array
    {
        $reasons = [];
        $add = function (string $code, int $points, array $params = []) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'params' => $params, 'points' => $points];
        };
        if ($t->deadline) {
            $deadline = CarbonImmutable::instance($t->deadline);
            $days = $now->setTimezone($tz)->startOfDay()->diffInDays($deadline->setTimezone($tz)->startOfDay(), false);
            if ($deadline->lt($now)) $add('overdue', 40, ['date' => $deadline->toIso8601String()]);
            elseif ($days < 1) $add('due_today', 30);
            elseif ($days <= 3) $add('due_soon', 20, ['days' => (int) $days]);
            elseif ($days <= 7) $add('due_this_week', 10, ['days' => (int) $days]);
        }
        match ($t->priority) {
            'p0' => $add('priority', 30, ['priority' => 'P0']),
            'p1' => $add('priority', 20, ['priority' => 'P1']),
            'p2' => $add('priority', 10, ['priority' => 'P2']),
            default => null,
        };
        if ((int) $t->importance >= 60) $add('importance', (int) round($t->importance / 100 * 20), ['value' => (int) $t->importance]);
        if ($t->goal_title && (int) $t->goal_importance >= 50) $add('goal', (int) round($t->goal_importance / 100 * 10), ['goal' => $t->goal_title]);
        if ((int) $t->unblocks > 0) $add('unblocks', min(15, 5 * (int) $t->unblocks), ['count' => (int) $t->unblocks]);
        if ($t->status === 'in_progress') $add('in_progress', 10);

        return ['score' => array_sum(array_column($reasons, 'points')), 'reasons' => $reasons];
    }

    /**
     * "What should I work on now?" — top unblocked tasks with their reasons, plus what is
     * scheduled right now and what is waiting on something.
     */
    public function whatNow(User $user, int $limit = 5): array
    {
        $tz = $user->preferredTimezone();
        $now = CarbonImmutable::now();
        $tasks = $this->candidates($user);
        $ranked = [];
        $blocked = [];
        foreach ($tasks as $t) {
            if ($t->blocked_by !== []) {
                $blocked[] = ['id' => $t->id, 'title' => $t->title, 'blocked_by' => $t->blocked_by];
                continue;
            }
            $s = $this->score($t, $now, $tz);
            $ranked[] = ['id' => $t->id, 'title' => $t->title, 'priority' => $t->priority, 'status' => $t->status, 'deadline' => $t->deadline?->toIso8601String(),
                'estimated_minutes' => (int) $t->estimated_minutes, 'score' => $s['score'], 'reasons' => $s['reasons']];
        }
        usort($ranked, fn ($a, $b) => [$b['score'], $a['deadline'] ?? '9999'] <=> [$a['score'], $b['deadline'] ?? '9999']);

        $scheduling = app(SchedulingService::class);
        $current = collect($scheduling->items($user, $now->subHours(12), $now->addHours(12)))
            ->first(fn ($i) => in_array($i['kind'], ['task', 'focus'], true) && ($i['status'] ?? null) !== 'completed'
                && CarbonImmutable::parse($i['starts_at'])->lte($now) && CarbonImmutable::parse($i['ends_at'])->gt($now));
        $today = $scheduling->dayCapacity($user, $now->setTimezone($tz));
        $window = $scheduling->window($user, $now->setTimezone($tz));
        $remaining = $window && $now->lt($window[1]) ? (int) max(0, $now->max($window[0])->diffInMinutes($window[1])) : 0;

        return [
            'generated_at' => $now->toIso8601String(),
            'now_scheduled' => $current,
            'remaining_work_minutes_today' => $remaining,
            'today' => $today,
            'ranked' => array_slice($ranked, 0, $limit),
            'blocked' => array_slice($blocked, 0, 10),
            'open_tasks' => $tasks->count(),
            'rules' => 'documented', // see TaskRanker docblock; shown as per-task reasons
        ];
    }
}
