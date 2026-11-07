<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Planner\EstimateService;
use App\Services\Planner\TaskBreakdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Task breakdown into subtasks (AI proposal + user-confirmed creation) and history-based estimates. */
final class BreakdownController extends Controller
{
    public function __construct(private readonly TaskBreakdownService $breakdown, private readonly EstimateService $estimates) {}

    public function show(Request $request, Task $task): JsonResponse
    {
        return response()->json([
            'task' => $task->only(['id', 'title', 'status', 'estimated_minutes']),
            'children' => $task->children()->orderBy('id')->get(['id', 'title', 'status', 'estimated_minutes', 'actual_minutes']),
            'ai_available' => $this->breakdown->aiAvailable($request->user()),
            'max_steps' => TaskBreakdownService::MAX_CREATE,
        ]);
    }

    public function propose(Request $request, Task $task): JsonResponse
    {
        if (!$this->breakdown->aiAvailable($request->user())) {
            return response()->json(['message' => __('breakdown.ai_unavailable')], 503);
        }
        try {
            return response()->json($this->breakdown->propose($request->user(), $task));
        } catch (\Illuminate\Validation\ValidationException|\App\Exceptions\PlanLimitReached|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            return response()->json(['message' => __('breakdown.ai_failed')], 503);
        }
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $created = $this->breakdown->create($request->user(), $task, $request->only(['steps', 'update_parent_estimate']));
        return response()->json(['created' => array_map(fn ($t) => $t->only(['id', 'title', 'estimated_minutes', 'parent_task_id']), $created),
            'parent' => $task->fresh()->only(['id', 'estimated_minutes']), 'message' => __('breakdown.created', ['count' => \App\Support\LocalDate::number(count($created), $request->user()->preferredLocale())])], 201);
    }

    public function estimate(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title' => 'required|string|max:255',
            'task_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'tag_ids' => 'nullable|array|max:20', 'tag_ids.*' => 'integer',
            'estimated_minutes' => 'nullable|integer|min:0|max:10000',
        ]);
        if (!empty($d['task_id'])) Task::query()->findOrFail($d['task_id']); // owner scope: 404 for someone else's task
        return response()->json($this->estimates->suggest($request->user(), ['exclude_id' => $d['task_id'] ?? null] + $d));
    }
}
