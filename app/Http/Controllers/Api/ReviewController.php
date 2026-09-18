<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Planner\AnalyticsService;
use App\Services\Planner\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class ReviewController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ReviewService $reviews,
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $type = $request->input('type', 'daily');
        abort_unless(in_array($type, ['daily','weekly'], true), 422);

        $from = Carbon::parse($request->input('from', now()->startOfDay()));
        $to = Carbon::parse($request->input('to', now()));
        $metrics = $this->analytics->summary($from, $to);

        return response()->json($this->reviews->generate($type, $from, $to, $metrics));
    }

    public function index(): JsonResponse
    {
        return response()->json(Review::query()->latest()->paginate(20));
    }
}
