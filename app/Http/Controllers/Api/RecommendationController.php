<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planner\RecommendationService;
use Illuminate\Http\JsonResponse;

final class RecommendationController extends Controller
{
    public function __invoke(RecommendationService $service): JsonResponse
    {
        return response()->json($service->build());
    }
}
