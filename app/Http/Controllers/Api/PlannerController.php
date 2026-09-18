<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planner\DailyPlannerEngine;
use App\Services\Planner\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class PlannerController extends Controller
{
    public function __construct(
        private readonly DailyPlannerEngine $daily,
        private readonly AnalyticsService $analytics,
    ) {}

    public function today(Request $request): JsonResponse
    {
        $minutes = max(1, (int) $request->integer('available_minutes', 480));
        return response()->json($this->daily->build($minutes));
    }

    public function analytics(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->input('from', now()->startOfMonth()));
        $to = Carbon::parse($request->input('to', now()));

        return response()->json($this->analytics->summary($from, $to));
    }
}
