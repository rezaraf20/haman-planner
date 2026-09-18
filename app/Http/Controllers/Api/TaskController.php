<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Planner\PlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskController extends Controller
{
 public function __construct(private readonly PlannerService $planner) {}
 public function index(Request $request): JsonResponse {
  $tasks=Task::query()->with(['goal','project','milestone'])->when($request->status,fn($q,$v)=>$q->where('status',$v))->orderByRaw("CASE priority WHEN 'p0' THEN 0 WHEN 'p1' THEN 1 WHEN 'p2' THEN 2 ELSE 3 END")->paginate(50);
  return response()->json($tasks);
 }
 public function store(Request $request): JsonResponse {
  $data=$request->validate(['title'=>'required|string|max:255','description'=>'nullable|string','area_id'=>'nullable|integer','goal_id'=>'nullable|integer','project_id'=>'nullable|integer','milestone_id'=>'nullable|integer','parent_task_id'=>'nullable|integer','status'=>'nullable|string','importance'=>'nullable|integer|min:0|max:100','weight'=>'nullable|numeric|min:0','estimated_minutes'=>'nullable|integer|min:0','deadline'=>'nullable|date']);
  return response()->json($this->planner->createTask($data),201);
 }
 public function show(Task $task): JsonResponse { return response()->json($task->load(['goal','project','milestone','children'])); }
 public function update(Request $request, Task $task): JsonResponse {
  $task->update($request->validate(['title'=>'sometimes|string|max:255','description'=>'nullable|string','status'=>'sometimes|string','priority'=>'sometimes|in:p0,p1,p2,p3','importance'=>'sometimes|integer|min:0|max:100','progress'=>'sometimes|numeric|min:0|max:100','estimated_minutes'=>'sometimes|integer|min:0','deadline'=>'nullable|date']));
  if ($task->status==='completed') $this->planner->complete($task);
  return response()->json($task->refresh());
 }
 public function destroy(Task $task): JsonResponse { $task->delete(); return response()->json(null,204); }
}