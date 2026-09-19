<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            DailyPlan::with('scheduleBlocks')->latest('plan_date')->paginate(31)
        );
    }

    public function show(string $date): JsonResponse
    {
        $plan = DailyPlan::firstOrCreate(
            ['plan_date' => $date],
            ['available_minutes' => 0, 'buffer_minutes' => 0]
        );

        return response()->json($plan->load('scheduleBlocks.task'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_date' => ['required','date'],
            'available_minutes' => ['nullable','integer','min:0'],
            'planned_minutes' => ['nullable','integer','min:0'],
            'completed_minutes' => ['nullable','integer','min:0'],
            'buffer_minutes' => ['nullable','integer','min:0'],
            'focus_level' => ['nullable','integer','min:0','max:100'],
            'energy_level' => ['nullable','integer','min:0','max:100'],
            'notes' => ['nullable','string'],
        ]);

        $plan = DailyPlan::updateOrCreate(['plan_date' => $data['plan_date']], $data);
        return response()->json($plan->load('scheduleBlocks'), 201);
    }

    public function update(Request $request, DailyPlan $dailyPlan): JsonResponse
    {
        $dailyPlan->update($request->validate([
            'available_minutes' => ['sometimes','integer','min:0'],
            'planned_minutes' => ['sometimes','integer','min:0'],
            'completed_minutes' => ['sometimes','integer','min:0'],
            'buffer_minutes' => ['sometimes','integer','min:0'],
            'focus_level' => ['nullable','integer','min:0','max:100'],
            'energy_level' => ['nullable','integer','min:0','max:100'],
            'notes' => ['nullable','string'],
        ]));

        return response()->json($dailyPlan->refresh()->load('scheduleBlocks'));
    }
}
