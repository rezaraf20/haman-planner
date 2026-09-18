<?php

declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Decision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class DecisionController extends Controller
{
 public function index(Request $request): JsonResponse { return response()->json(Decision::query()->latest('decided_at')->paginate(50)); }
 public function store(Request $request): JsonResponse { $data=$request->validate(['area_id'=>'nullable|integer|exists:areas,id','title'=>'required|string|max:255','decision'=>'required|string','rationale'=>'nullable|string','decided_at'=>'nullable|date']); return response()->json(Decision::create($data),201); }
 public function show(Decision $decision): JsonResponse { return response()->json($decision); }
 public function update(Request $request, Decision $decision): JsonResponse { $data=$request->validate(['title'=>'sometimes|string|max:255','decision'=>'sometimes|string','rationale'=>'nullable|string','area_id'=>'nullable|integer|exists:areas,id','decided_at'=>'nullable|date']); $decision->update($data); return response()->json($decision->refresh()); }
 public function destroy(Decision $decision): JsonResponse { $decision->delete(); return response()->json(null,204); }
}
