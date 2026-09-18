<?php

declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class NoteController extends Controller
{
 public function index(Request $request): JsonResponse { $notes=Note::query()->when($request->q,fn($q,$v)=>$q->where(fn($x)=>$x->where('title','ilike','%'.$v.'%')->orWhere('content','ilike','%'.$v.'%')))->latest('id')->paginate(50); return response()->json($notes); }
 public function store(Request $request): JsonResponse { $data=$request->validate(['area_id'=>'nullable|integer|exists:areas,id','goal_id'=>'nullable|integer|exists:goals,id','project_id'=>'nullable|integer|exists:projects,id','task_id'=>'nullable|integer|exists:tasks,id','title'=>'required|string|max:255','content'=>'required|string']); return response()->json(Note::create($data),201); }
 public function show(Note $note): JsonResponse { return response()->json($note); }
 public function update(Request $request, Note $note): JsonResponse { $data=$request->validate(['title'=>'sometimes|string|max:255','content'=>'sometimes|string','area_id'=>'nullable|integer|exists:areas,id','goal_id'=>'nullable|integer|exists:goals,id','project_id'=>'nullable|integer|exists:projects,id','task_id'=>'nullable|integer|exists:tasks,id']); $note->update($data); return response()->json($note->refresh()); }
 public function destroy(Note $note): JsonResponse { $note->delete(); return response()->json(null,204); }
}
