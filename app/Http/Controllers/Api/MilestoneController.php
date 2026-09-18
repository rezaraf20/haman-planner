<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MilestoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $milestones = Milestone::query()
            ->with('project')
            ->when($request->project_id, fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(50);

        return response()->json($milestones);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'title' => 'required|string|max:255',
            'status' => 'nullable|string|max:50',
            'weight' => 'nullable|numeric|min:0',
            'progress' => 'nullable|numeric|min:0|max:100',
            'target_date' => 'nullable|date',
        ]);

        return response()->json(Milestone::create($data)->load('project'), 201);
    }

    public function show(Milestone $milestone): JsonResponse
    {
        return response()->json($milestone->load(['project', 'tasks']));
    }

    public function update(Request $request, Milestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'sometimes|integer|exists:projects,id',
            'title' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:50',
            'weight' => 'sometimes|numeric|min:0',
            'progress' => 'sometimes|numeric|min:0|max:100',
            'target_date' => 'sometimes|nullable|date',
            'completed_at' => 'sometimes|nullable|date',
        ]);

        $milestone->update($data);

        return response()->json($milestone->refresh()->load('project'));
    }

    public function destroy(Milestone $milestone): JsonResponse
    {
        $milestone->delete();

        return response()->json(null, 204);
    }
}
