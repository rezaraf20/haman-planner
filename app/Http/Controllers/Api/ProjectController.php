<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Planner\ProgressPropagationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectController extends Controller
{
    public function __construct(private readonly ProgressPropagationService $progressPropagation) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => 'nullable|in:open,closed,archived,all', 'per_page' => 'nullable|integer|min:1|max:200']);
        $projects = Project::query()
            ->with(['goal', 'milestones'])
            ->inState($data['state'] ?? null)
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])])
            ->when($request->goal_id, fn ($q, $v) => $q->where('goal_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw('archived_at desc nulls last')->orderByRaw('completed_at desc nulls last')
            ->latest('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json($projects);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal_id' => 'nullable|integer|exists:goals,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|'.Project::statusRule(),
            'importance' => 'nullable|integer|min:0|max:100',
            'weight' => 'nullable|numeric|min:0',
            'progress' => 'nullable|numeric|min:0|max:100',
            'health' => 'nullable|string|max:50',
            'estimated_minutes' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'target_date' => 'nullable|date',
        ]);

        $project = Project::create($data);
        if ($project->goal) $this->progressPropagation->goal($project->goal);
        return response()->json($project->load('goal'), 201);
    }

    public function show(Project $project): JsonResponse
    {
        return response()->json($project->load(['goal', 'milestones', 'tasks']));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'goal_id' => 'sometimes|nullable|integer|exists:goals,id',
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|nullable|'.Project::statusRule($project->status),
            'importance' => 'sometimes|nullable|integer|min:0|max:100',
            'weight' => 'sometimes|nullable|numeric|min:0',
            'progress' => 'sometimes|nullable|numeric|min:0|max:100',
            'health' => 'sometimes|nullable|string|max:50',
            'estimated_minutes' => 'sometimes|nullable|integer|min:0',
            'actual_minutes' => 'sometimes|nullable|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'target_date' => 'sometimes|nullable|date',
        ]);

        $oldGoalId = $project->goal_id;
        $project->update($data);
        $this->progressPropagation->project($project);
        if ($oldGoalId && $oldGoalId !== $project->goal_id) { $old = \App\Models\Goal::find($oldGoalId); if ($old) $this->progressPropagation->goal($old); }
        if ($project->goal) $this->progressPropagation->goal($project->goal);

        return response()->json($project->refresh()->load('goal'));
    }

    public function destroy(Project $project): JsonResponse
    {
        $goal = $project->goal()->first();
        $project->delete();
        if ($goal) $this->progressPropagation->goal($goal);
        return response()->json(null, 204);
    }
}
