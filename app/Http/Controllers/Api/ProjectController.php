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
        $projects = Project::query()
            ->with(['goal', 'milestones'])
            ->when($request->goal_id, fn ($q, $v) => $q->where('goal_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(50);

        return response()->json($projects);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'goal_id' => 'nullable|integer|exists:goals,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|max:50',
            'importance' => 'nullable|integer|min:0|max:100',
            'weight' => 'nullable|numeric|min:0',
            'progress' => 'nullable|numeric|min:0|max:100',
            'health' => 'nullable|string|max:50',
            'estimated_minutes' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'target_date' => 'nullable|date',
        ]);

        $project = Project::create($data);

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
            'status' => 'sometimes|string|max:50',
            'importance' => 'sometimes|integer|min:0|max:100',
            'weight' => 'sometimes|numeric|min:0',
            'progress' => 'sometimes|numeric|min:0|max:100',
            'health' => 'sometimes|string|max:50',
            'estimated_minutes' => 'sometimes|integer|min:0',
            'actual_minutes' => 'sometimes|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'target_date' => 'sometimes|nullable|date',
        ]);

        $project->update($data);
        $this->progressPropagation->project($project);

        return response()->json($project->refresh()->load('goal'));
    }

    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json(null, 204);
    }
}
