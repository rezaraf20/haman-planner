<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Services\Planner\ActivityLogger;
use App\Services\Planner\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Time blocking API used by the calendar: read a range, place a task or block, move/resize,
 * unschedule. Overlaps with work or busy calendar time return 409 with the conflicts unless
 * the client confirms with force=true — nothing is overbooked silently.
 */
final class ScheduleController extends Controller
{
    public function __construct(private readonly SchedulingService $scheduling, private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = CarbonImmutable::parse($data['from'])->toDateString();
        $to = CarbonImmutable::parse($data['to'])->toDateString();
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 42, 422, __('schedule.range_too_long'));

        $user = $request->user();
        $unscheduled = Task::query()->whereNull('planned_start')->whereIn('status', ['inbox', 'planned', 'ready', 'in_progress', 'deferred'])
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")->orderBy('deadline')
            ->limit(60)->get(['id', 'title', 'priority', 'status', 'deadline', 'estimated_minutes', 'recurring_task_id']);

        return response()->json($this->scheduling->range($user, $from, $to) + [
            'unscheduled' => $unscheduled,
            'settings' => [
                'default_task_minutes' => (int) $user->preference('default_task_minutes'),
                'break_minutes' => (int) $user->preference('break_minutes'),
                'buffer_percent' => (int) $user->preference('planning_buffer_percent'),
            ],
        ]);
    }

    /** Put a task (or a focus/break/buffer block) on the calendar. */
    public function place(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'task_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(ScheduleBlock::KINDS)],
            'title' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_fixed' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean'],
        ]);
        $task = isset($data['task_id']) ? Task::query()->findOrFail($data['task_id']) : null;
        $kind = $task ? 'task' : ($data['kind'] ?? 'focus');
        abort_if(!$task && $kind === 'task', 422, __('schedule.task_required'));

        $start = CarbonImmutable::parse($data['starts_at']);
        $minutes = $task && $task->estimated_minutes > 0 ? (int) $task->estimated_minutes
            : ($kind === 'break' ? (int) $user->preference('break_minutes') : (int) $user->preference('default_task_minutes'));
        $end = isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : $start->addMinutes(max(5, $minutes));

        if (in_array($kind, ['task', 'focus'], true) && !$request->boolean('force')) {
            $conflicts = $this->scheduling->conflictsFor($user, $start, $end, null, null, $task?->id);
            if ($conflicts !== []) {
                return $this->conflict($conflicts);
            }
        }

        $block = DB::transaction(function () use ($task, $kind, $data, $start, $end) {
            $block = ScheduleBlock::create([
                'task_id' => $task?->id, 'kind' => $kind, 'title' => $data['title'] ?? null, 'starts_at' => $start, 'ends_at' => $end,
                'is_fixed' => (bool) ($data['is_fixed'] ?? false), 'source' => 'calendar', 'status' => 'planned',
            ]);
            if ($task) {
                $this->syncTask($task, $start, $end);
            }
            return $block;
        });
        return response()->json($block->load('task:id,title,status,priority'), 201);
    }

    /** Move or resize a block or a scheduled task. */
    public function move(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless(in_array($type, ['block', 'task'], true), 404);
        $user = $request->user();
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'force' => ['nullable', 'boolean'],
        ]);
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = CarbonImmutable::parse($data['ends_at']);

        if ($type === 'block') {
            $block = ScheduleBlock::query()->findOrFail($id);
            $isWork = in_array($block->kind ?: 'task', ['task', 'focus'], true);
            if ($isWork && !$request->boolean('force')) {
                $conflicts = $this->scheduling->conflictsFor($user, $start, $end, 'block', $block->id, $block->task_id);
                if ($conflicts !== []) return $this->conflict($conflicts);
            }
            DB::transaction(function () use ($block, $start, $end): void {
                $before = ['starts_at' => $block->starts_at->toIso8601String(), 'ends_at' => $block->ends_at->toIso8601String()];
                $block->update(['starts_at' => $start, 'ends_at' => $end]);
                if ($block->task_id && ($task = Task::query()->find($block->task_id))) {
                    $this->syncTask($task, $start, $end);
                } else {
                    $this->activity->log('rescheduled', ScheduleBlock::class, $block->id, $before, ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()]);
                }
            });
            return response()->json($block->refresh()->load('task:id,title,status,priority'));
        }

        $task = Task::query()->findOrFail($id);
        if (!$request->boolean('force')) {
            $conflicts = $this->scheduling->conflictsFor($user, $start, $end, 'task', $task->id, $task->id);
            if ($conflicts !== []) return $this->conflict($conflicts);
        }
        $this->syncTask($task, $start, $end);
        return response()->json($task->refresh());
    }

    /** Take a task off the calendar (its blocks are removed; the task itself stays). */
    public function unschedule(Task $task): JsonResponse
    {
        DB::transaction(function () use ($task): void {
            ScheduleBlock::query()->where('task_id', $task->id)->where('starts_at', '>=', now()->startOfDay())->get()->each->delete();
            $before = ['planned_start' => $task->planned_start?->toIso8601String(), 'planned_end' => $task->planned_end?->toIso8601String()];
            $task->update(['planned_start' => null, 'planned_end' => null]);
            $this->activity->log('unscheduled', Task::class, $task->id, $before, null);
        });
        return response()->json($task->refresh());
    }

    /** Keep the task's planned time in step with its calendar placement. */
    private function syncTask(Task $task, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $before = ['planned_start' => $task->planned_start?->toIso8601String(), 'planned_end' => $task->planned_end?->toIso8601String()];
        $task->planned_start = $start;
        $task->planned_end = $end;
        if ($task->status === 'inbox') {
            $task->status = 'planned';
        }
        if ($task->isOccurrence() && $task->recurrence_exception === null && $task->isDirty(['planned_start', 'planned_end'])) {
            $task->recurrence_exception = 'moved';
        }
        $changed = $task->isDirty(['planned_start', 'planned_end']);
        $task->save();
        if ($changed) {
            $this->activity->log($before['planned_start'] ? 'rescheduled' : 'scheduled', Task::class, $task->id, $before,
                ['planned_start' => $start->toIso8601String(), 'planned_end' => $end->toIso8601String()]);
        }
    }

    private function conflict(array $conflicts): JsonResponse
    {
        return response()->json([
            'message' => __('schedule.conflict', ['count' => count($conflicts)]),
            'conflicts' => $conflicts,
            'requires_confirmation' => true,
        ], 409);
    }
}
