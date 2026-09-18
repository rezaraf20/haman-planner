<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GoalController extends Controller
{
 public function index(): JsonResponse { return response()->json(Goal::with('projects')->orderBy('target_date')->paginate(50)); }
 public function store(Request $request): JsonResponse {
  $data=$request->validate(['area_id'=>'nullable|integer','title'=>'required|string|max:255','description'=>'nullable|string','status'=>'nullable|string','start_date'=>'nullable|date','target_date'=>'nullable|date','importance'=>'nullable|integer|min:0|max:100','weight'=>'nullable|numeric|min:0','success_criteria'=>'nullable|string']);
  return response()->json(Goal::create($data),201);
 }
 public function show(Goal $goal): JsonResponse { return response()->json($goal->load(['area','projects','tasks'])); }
 public function update(Request $request, Goal $goal): JsonResponse { $goal->update($request->validate(['title'=>'sometimes|string|max:255','description'=>'nullable|string','status'=>'sometimes|string','target_date'=>'nullable|date','importance'=>'sometimes|integer|min:0|max:100','progress'=>'sometimes|numeric|min:0|max:100','health'=>'sometimes|string','success_criteria'=>'nullable|string'])); return response()->json($goal->refresh()); }
 public function destroy(Goal $goal): JsonResponse { $goal->delete(); return response()->json(null,204); }
}