<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExecutionLog;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExecutionLogController extends Controller
{
    public function index(Task $task): JsonResponse
    {
        return response()->json($task->executionLogs()->latest('started_at')->paginate(50));
    }

    public function update(Request $request, Task $task, ExecutionLog $executionLog): JsonResponse
    {
        abort_unless((int) $executionLog->task_id === (int) $task->id, 404);
        $data = $request->validate([
            'started_at' => 'sometimes|date', 'ended_at' => 'nullable|date|after_or_equal:started_at',
            'duration_minutes' => 'nullable|integer|min:0', 'focus_level' => 'nullable|integer|min:0|max:100',
            'energy_level' => 'nullable|integer|min:0|max:100', 'result' => 'nullable|string',
            'blocker' => 'nullable|string', 'notes' => 'nullable|string',
        ]);
        $executionLog->update($data);
        return response()->json($executionLog->refresh());
    }

    public function destroy(Task $task, ExecutionLog $executionLog): JsonResponse
    {
        abort_unless((int) $executionLog->task_id === (int) $task->id, 404);
        $executionLog->delete();
        return response()->json(null, 204);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'started_at' => 'required|date',
            'ended_at' => 'nullable|date|after_or_equal:started_at',
            'duration_minutes' => 'nullable|integer|min:0',
            'focus_level' => 'nullable|integer|min:0|max:100',
            'energy_level' => 'nullable|integer|min:0|max:100',
            'result' => 'nullable|string',
            'blocker' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $log = $task->executionLogs()->create($data);

        if (($data['duration_minutes'] ?? 0) > 0) {
            $task->increment('actual_minutes', (int) $data['duration_minutes']);
        }

        return response()->json($log, 201);
    }
}
