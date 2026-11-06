<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Services\Planner\ProgressPropagationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MilestoneController extends Controller
{
    public function __construct(private readonly ProgressPropagationService $progressPropagation) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['state' => 'nullable|in:open,closed,archived,all', 'per_page' => 'nullable|integer|min:1|max:200']);
        $milestones = Milestone::query()
            ->with('project')
            ->inState($data['state'] ?? null)
            ->withCount(['tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])])
            ->when($request->project_id, fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw('archived_at desc nulls last')
            ->latest('id')
            ->paginate($data['per_page'] ?? 50);

        return response()->json($milestones);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'status' => 'nullable|'.Milestone::statusRule(),
            'weight' => 'nullable|numeric|min:0',
            'progress' => 'nullable|numeric|min:0|max:100',
            'target_date' => 'nullable|date',
        ]);

        $milestone = Milestone::create($data);
        $this->progressPropagation->milestone($milestone);
        if ($p = $milestone->project()->first()) $this->progressPropagation->project($p);
        return response()->json($milestone->refresh()->load('project'), 201);
    }

    public function show(Milestone $milestone): JsonResponse
    {
        return response()->json($milestone->load(['project', 'tasks']));
    }

    public function update(Request $request, Milestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'sometimes|nullable|integer|exists:projects,id',
            'title' => 'sometimes|string|max:255',
            'status' => 'sometimes|nullable|'.Milestone::statusRule($milestone->status),
            'weight' => 'sometimes|nullable|numeric|min:0',
            'progress' => 'sometimes|nullable|numeric|min:0|max:100',
            'target_date' => 'sometimes|nullable|date',
            'completed_at' => 'sometimes|nullable|date',
        ]);

        $oldProjectId = $milestone->project_id;
        $milestone->update($data);
        $this->progressPropagation->milestone($milestone);
        if ($p = $milestone->project()->first()) $this->progressPropagation->project($p);
        if ($oldProjectId && $oldProjectId !== $milestone->project_id) { $old = \App\Models\Project::find($oldProjectId); if ($old) $this->progressPropagation->project($old); }

        return response()->json($milestone->refresh()->load('project'));
    }

    public function destroy(Milestone $milestone): JsonResponse
    {
        $project = $milestone->project()->first();
        $milestone->delete();
        if ($project) $this->progressPropagation->project($project);
        return response()->json(null, 204);
    }
}
