<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Services\Planner\ProgressPropagationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GoalController extends Controller
{
 public function __construct(private readonly ProgressPropagationService $progress) {}

 /** ?state=open|closed|archived|all (default: everything except archived), ?per_page up to 200 */
 public function index(Request $request): JsonResponse {
  $data=$request->validate(['state'=>'nullable|in:open,closed,archived,all','per_page'=>'nullable|integer|min:1|max:200']);
  return response()->json(Goal::query()->with('projects')->inState($data['state'] ?? null)
   ->withCount(['tasks as open_tasks_count'=>fn($q)=>$q->whereNotIn('status',['completed','cancelled'])])
   ->orderByRaw('archived_at desc nulls last')->orderByRaw('completed_at desc nulls last')->orderByRaw('target_date asc nulls last')->orderByDesc('id')
   ->paginate($data['per_page'] ?? 50));
 }
 public function store(Request $request): JsonResponse {
  $data=$request->validate(['area_id'=>'nullable|integer','title'=>'required|string|max:255','description'=>'nullable|string','status'=>'nullable|'.Goal::statusRule(),'start_date'=>'nullable|date','target_date'=>'nullable|date|after_or_equal:start_date','importance'=>'nullable|integer|min:0|max:100','weight'=>'nullable|numeric|min:0','success_criteria'=>'nullable|string']);
  return response()->json(Goal::create($data),201);
 }
 public function show(Goal $goal): JsonResponse { return response()->json($goal->load(['area','projects','tasks'])); }
 public function update(Request $request, Goal $goal): JsonResponse {
  $data=$request->validate(['title'=>'sometimes|string|max:255','area_id'=>'sometimes|nullable|integer','description'=>'nullable|string','status'=>'sometimes|nullable|'.Goal::statusRule($goal->status),'start_date'=>'sometimes|nullable|date','target_date'=>'sometimes|nullable|date','importance'=>'sometimes|nullable|integer|min:0|max:100','weight'=>'sometimes|nullable|numeric|min:0','progress'=>'sometimes|nullable|numeric|min:0|max:100','health'=>'sometimes|nullable|string','success_criteria'=>'nullable|string']);
  $goal->update($data);
  if (array_key_exists('status',$data)) $this->progress->goal($goal);
  return response()->json($goal->refresh());
 }
 public function destroy(Goal $goal): JsonResponse { $goal->delete(); return response()->json(null,204); }
}
