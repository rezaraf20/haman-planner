<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScheduleBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScheduleBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            ScheduleBlock::with('task')->when($request->date, fn($q,$date) => $q->whereDate('starts_at',$date))
                ->orderBy('starts_at')->paginate(100)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'daily_plan_id' => ['nullable','integer','exists:daily_plans,id'],
            'task_id' => ['nullable','integer','exists:tasks,id'],
            'starts_at' => ['required','date'],
            'ends_at' => ['required','date','after:starts_at'],
            'source' => ['nullable','string','max:50'],
            'status' => ['nullable','string','max:50'],
        ]);

        return response()->json(ScheduleBlock::create($data)->load('task'), 201);
    }

    public function update(Request $request, ScheduleBlock $scheduleBlock): JsonResponse
    {
        $scheduleBlock->update($request->validate([
            'daily_plan_id' => ['nullable','integer','exists:daily_plans,id'],
            'task_id' => ['nullable','integer','exists:tasks,id'],
            'starts_at' => ['sometimes','date'],
            'ends_at' => ['sometimes','date','after:starts_at'],
            'source' => ['sometimes','string','max:50'],
            'status' => ['sometimes','string','max:50'],
        ]));

        return response()->json($scheduleBlock->refresh()->load('task'));
    }

    public function destroy(ScheduleBlock $scheduleBlock): JsonResponse
    {
        $scheduleBlock->delete();
        return response()->json(null, 204);
    }
}
