<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Planner\DependencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class DependencyController extends Controller
{
    public function __construct(private readonly DependencyService $service) {}

    public function index(Task $task): JsonResponse
    {
        return response()->json($task->dependencies()->with('dependsOn')->get());
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'depends_on_task_id' => 'required|integer|exists:tasks,id',
            'type' => 'nullable|in:blocks,requires,related',
        ]);

        try {
            $dependency = $this->service->add(
                $task,
                Task::findOrFail($data['depends_on_task_id']),
                $data['type'] ?? 'requires'
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($dependency->load('dependsOn'), 201);
    }

    public function destroy(Task $task, int $dependency): JsonResponse
    {
        $deleted = $task->dependencies()->whereKey($dependency)->delete();
        return response()->json(null, $deleted ? 204 : 404);
    }
}
