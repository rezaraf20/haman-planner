<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Services\Planner\PlannerService;
use App\Services\Planner\ProgressPropagationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskController extends Controller
{
    public function __construct(
        private readonly PlannerService $planner,
        private readonly ProgressPropagationService $progressPropagation
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|string',
            'priority' => 'nullable|string',
            'area_id' => 'nullable|integer',
            'goal_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'milestone_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'overdue' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
            'state' => 'nullable|in:open,closed,all',
            'failed' => 'nullable|boolean',
            'hide_future' => 'nullable|boolean',
            'smart' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Planner\TaskFilter::PRESETS))],
            'filter' => 'nullable|string|max:2000',
            'tag' => 'nullable|integer',
        ]);
        // Smart list / saved or custom filter (validated definition, applied with the query builder).
        $filters = app(\App\Services\Planner\TaskFilter::class);
        $definition = null;
        if (!empty($data['smart']) || !empty($data['filter']) || !empty($data['tag'])) {
            $raw = !empty($data['filter']) ? json_decode($data['filter'], true) : [];
            if (!is_array($raw)) throw \Illuminate\Validation\ValidationException::withMessages(['filter' => __('filters.invalid')]);
            if (!empty($data['smart'])) $raw = array_merge(\App\Services\Planner\TaskFilter::PRESETS[$data['smart']], $raw);
            if (!empty($data['tag'])) $raw['tags'] = array_values(array_unique(array_merge((array) ($raw['tags'] ?? []), [(int) $data['tag']])));
            if (isset($data['state']) && !isset($raw['state'])) $raw['state'] = $data['state'];
            $definition = $filters->validate($raw, $request->user());
        }

        // Recurring occurrences further ahead than tomorrow clutter the list; they appear when due.
        $horizon = now($request->user()?->timezone ?: config('app.timezone'))->addDay()->endOfDay()->utc();
        $future = fn ($q) => $q->whereNotNull('recurring_task_id')
            ->whereRaw('COALESCE(planned_start, deadline) > ?', [$horizon]);
        $hideFuture = (bool) ($data['hide_future'] ?? false);

        $tasks = Task::query()->with(['goal', 'project', 'milestone', 'tags:id,name,color'])
            ->when($definition !== null, fn ($q) => $filters->apply($q, $definition, $request->user()))
            ->when(($data['state'] ?? null) === 'open', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->when(($data['state'] ?? null) === 'closed', fn ($q) => $q->whereIn('status', ['completed', 'cancelled']))
            ->when($hideFuture, fn ($q) => $q->whereNot(fn ($w) => $future($w)))
            ->when($data['failed'] ?? false, fn ($q) => $q->whereNotNull('failure_reason'))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($data['area_id'] ?? null, fn ($q, $v) => $q->where('area_id', $v))
            ->when($data['goal_id'] ?? null, fn ($q, $v) => $q->where('goal_id', $v))
            ->when($data['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($data['milestone_id'] ?? null, fn ($q, $v) => $q->where('milestone_id', $v))
            ->when($data['q'] ?? null, fn ($q, $v) => $q->where(
                fn ($x) => $x->where('title', 'ilike', '%'.$v.'%')
                    ->orWhere('description', 'ilike', '%'.$v.'%')
            ))
            ->when(
                ($data['overdue'] ?? false),
                fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])
                    ->whereNotNull('deadline')
                    ->where('deadline', '<', now())
            )
            ->when(($data['state'] ?? null) === 'closed', fn ($q) => $q->orderByDesc('completed_at')->orderByDesc('updated_at'))
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")
            ->orderByRaw('deadline asc nulls last')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        $out = $tasks->toArray();
        if ($hideFuture) {
            $out['hidden_future'] = Task::query()->whereNotIn('status', ['completed', 'cancelled'])->where(fn ($w) => $future($w))->count();
        }
        return response()->json($out);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'area_id' => 'nullable|integer',
            'goal_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'milestone_id' => 'nullable|integer',
            'parent_task_id' => 'nullable|integer',
            'status' => ['nullable', \Illuminate\Validation\Rule::enum(\App\Domain\Planner\TaskStatus::class)],
            'priority' => 'nullable|in:p0,p1,p2,p3',
            'importance' => 'nullable|integer|min:0|max:100',
            'weight' => 'nullable|numeric|min:0',
            'estimated_minutes' => 'nullable|integer|min:0',
            'deadline' => 'nullable|date',
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
            'energy_level' => 'nullable|integer|min:0|max:100',
            'focus_level' => 'nullable|integer|min:0|max:100',
        ]);

        $task = $this->planner->createTask($data);
        $this->syncTags($request, $task);
        $this->progressPropagation->recalculateFromTask($task);

        return response()->json($task->refresh()->load('tags:id,name,color'), 201);
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json($task->load(['goal', 'project', 'milestone', 'children', 'tags:id,name,color']));
    }

    /** tag_ids: existing tags (must be the user's); new_tags: names to create (or reuse, case-insensitive). */
    private function syncTags(Request $request, Task $task): void
    {
        if (!$request->has('tag_ids') && !$request->has('new_tags')) return;
        $data = $request->validate([
            'tag_ids' => 'nullable|array|max:20', 'tag_ids.*' => 'integer',
            'new_tags' => 'nullable|array|max:10', 'new_tags.*' => ['string', 'max:40', 'regex:/^[^,#<>]+$/u'],
        ]);
        $ids = array_map('intval', $data['tag_ids'] ?? []);
        if ($ids && \App\Models\Tag::query()->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tag_ids' => __('filters.not_found')]);
        }
        foreach ($data['new_tags'] ?? [] as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            if ($name === '') continue;
            $tag = \App\Models\Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? \App\Models\Tag::create(['name' => $name]);
            $ids[] = $tag->id;
        }
        $task->tags()->sync(array_values(array_unique($ids)));
    }

    /** Why a task slipped: reason (catalog code) + optional note. The task's description is left alone. */
    public function logFailure(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys((array) __('planner.failure_reason')))],
            'note' => 'nullable|string|max:1000',
        ]);
        return response()->json($this->planner->logFailure($task, $data)->load(['goal', 'project', 'milestone']));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'area_id' => 'sometimes|nullable|integer',
            'goal_id' => 'sometimes|nullable|integer|exists:goals,id',
            'project_id' => 'sometimes|nullable|integer|exists:projects,id',
            'milestone_id' => 'sometimes|nullable|integer|exists:milestones,id',
            'parent_task_id' => 'sometimes|nullable|integer|exists:tasks,id',
            'status' => ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(array_unique(array_merge(array_column(\App\Domain\Planner\TaskStatus::cases(), 'value'), [(string) $task->status])))],
            'priority' => 'sometimes|nullable|in:p0,p1,p2,p3',
            'importance' => 'sometimes|nullable|integer|min:0|max:100',
            'progress' => 'sometimes|nullable|numeric|min:0|max:100',
            'estimated_minutes' => 'sometimes|nullable|integer|min:0',
            'deadline' => 'nullable|date',
            'planned_start' => 'sometimes|nullable|date',
            'planned_end' => 'sometimes|nullable|date|after_or_equal:planned_start',
            'energy_level' => 'sometimes|nullable|integer|min:0|max:100',
            'focus_level' => 'sometimes|nullable|integer|min:0|max:100',
            'failure_reason' => 'sometimes|nullable|string|max:100',
            'weight' => 'sometimes|nullable|numeric|min:0',
        ]);

        if (isset($data['parent_task_id']) && (int) $data['parent_task_id'] === (int) $task->id) {
            return response()->json(['message' => 'A task cannot be its own parent.'], 422);
        }

        $oldGoalId = $task->goal_id;
        $oldProjectId = $task->project_id;
        $oldMilestoneId = $task->milestone_id;

        if (isset($data['status']) && $data['status'] !== 'completed') {
            $data['completed_at'] = null;
        }
        if (isset($data['status']) && $data['status'] === 'completed') {
            $data['progress'] = 100;
        }

        $plannedBefore = [$task->planned_start?->toIso8601String(), $task->planned_end?->toIso8601String()];
        $task->fill($data);
        // Recurring occurrences: remember that this one was changed on its own, so later
        // "edit all future occurrences" updates leave it alone.
        if ($task->isOccurrence() && $task->recurrence_exception !== 'skipped') {
            if ($task->isDirty(\App\Models\RecurringTask::TEMPLATE_FIELDS)) {
                $task->recurrence_exception = 'edited';
            } elseif ($task->isDirty(['planned_start', 'planned_end']) && $task->recurrence_exception === null) {
                $task->recurrence_exception = 'moved';
            }
        }
        $deadlineChanged = $task->isDirty('deadline') && $task->getOriginal('deadline') !== null;
        $task->save();
        if ($deadlineChanged) {
            app(\App\Services\Planner\ActivityLogger::class)->log('deadline_changed', Task::class, $task->id, null, ['deadline' => $task->deadline?->toIso8601String()]);
        }
        if ($plannedBefore[0] !== null && $plannedBefore !== [$task->planned_start?->toIso8601String(), $task->planned_end?->toIso8601String()]) {
            app(\App\Services\Planner\ActivityLogger::class)->log('rescheduled', Task::class, $task->id,
                ['planned_start' => $plannedBefore[0], 'planned_end' => $plannedBefore[1]],
                ['planned_start' => $task->planned_start?->toIso8601String(), 'planned_end' => $task->planned_end?->toIso8601String()]);
        }

        if ($task->status === 'completed') {
            $this->planner->complete($task);
        } else {
            $this->planner->recalculatePriority($task);
        }

        $this->syncTags($request, $task);
        $this->progressPropagation->recalculateFromTask($task);

        if ($oldMilestoneId && $oldMilestoneId !== $task->milestone_id) {
            $old = Milestone::find($oldMilestoneId);
            if ($old) $this->progressPropagation->milestone($old);
        }
        if ($oldProjectId && $oldProjectId !== $task->project_id) {
            $old = Project::find($oldProjectId);
            if ($old) $this->progressPropagation->project($old);
        }
        if ($oldGoalId && $oldGoalId !== $task->goal_id) {
            $old = Goal::find($oldGoalId);
            if ($old) $this->progressPropagation->goal($old);
        }

        return response()->json($task->refresh()->load('tags:id,name,color'));
    }

    public function destroy(Task $task): JsonResponse
    {
        $goal = $task->goal()->first();
        $project = $task->project()->first();
        $milestone = $task->milestone()->first();

        app(\App\Services\Planner\ActivityLogger::class)->log('deleted', Task::class, $task->id, ['title' => $task->title], null);
        $task->delete();

        if ($milestone) $this->progressPropagation->milestone($milestone);
        if ($project) $this->progressPropagation->project($project);
        if ($goal) $this->progressPropagation->goal($goal);

        return response()->json(null, 204);
    }
}
