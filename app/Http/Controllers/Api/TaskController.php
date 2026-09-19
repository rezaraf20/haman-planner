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
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $tasks = Task::query()->with(['goal', 'project', 'milestone'])
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
            ->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")
            ->orderBy('deadline')
            ->paginate($data['per_page'] ?? 50);

        return response()->json($tasks);
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
            'status' => 'nullable|string',
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
        $this->progressPropagation->recalculateFromTask($task);

        return response()->json($task->refresh(), 201);
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json($task->load(['goal', 'project', 'milestone', 'children']));
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
            'status' => 'sometimes|string',
            'priority' => 'sometimes|in:p0,p1,p2,p3',
            'importance' => 'sometimes|integer|min:0|max:100',
            'progress' => 'sometimes|numeric|min:0|max:100',
            'estimated_minutes' => 'sometimes|integer|min:0',
            'deadline' => 'nullable|date',
            'planned_start' => 'sometimes|nullable|date',
            'planned_end' => 'sometimes|nullable|date|after_or_equal:planned_start',
            'energy_level' => 'sometimes|nullable|integer|min:0|max:100',
            'focus_level' => 'sometimes|nullable|integer|min:0|max:100',
            'weight' => 'sometimes|numeric|min:0',
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

        $task->update($data);

        if ($task->status === 'completed') {
            $this->planner->complete($task);
        } else {
            $this->planner->recalculatePriority($task);
        }

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

        return response()->json($task->refresh());
    }

    public function destroy(Task $task): JsonResponse
    {
        $goal = $task->goal()->first();
        $project = $task->project()->first();
        $milestone = $task->milestone()->first();

        $task->delete();

        if ($milestone) $this->progressPropagation->milestone($milestone);
        if ($project) $this->progressPropagation->project($project);
        if ($goal) $this->progressPropagation->goal($goal);

        return response()->json(null, 204);
    }
}
