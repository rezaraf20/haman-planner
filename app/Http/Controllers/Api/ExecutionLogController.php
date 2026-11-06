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
        $old = (int) $executionLog->duration_minutes;
        $executionLog->fill($data);
        // Duration follows start/end unless given explicitly.
        if (!array_key_exists('duration_minutes', $data) || $data['duration_minutes'] === null) {
            $executionLog->duration_minutes = $executionLog->ended_at ? max(0, (int) $executionLog->started_at->diffInMinutes($executionLog->ended_at)) : $old;
        }
        $executionLog->save();
        $this->adjust($task, (int) $executionLog->duration_minutes - $old);
        return response()->json($executionLog->refresh());
    }

    public function destroy(Task $task, ExecutionLog $executionLog): JsonResponse
    {
        abort_unless((int) $executionLog->task_id === (int) $task->id, 404);
        $this->adjust($task, -(int) $executionLog->duration_minutes);
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

        // Same path as the bot: duration from start/end, actual minutes and the activity history updated.
        $log = app(\App\Services\Planner\PlannerService::class)->logTime($task, $data);

        return response()->json($log, 201);
    }

    /** Keep the task's actual_minutes equal to the sum of its logs. */
    private function adjust(Task $task, int $delta): void
    {
        if ($delta !== 0) {
            $task->update(['actual_minutes' => max(0, (int) $task->actual_minutes + $delta)]);
        }
    }
}
