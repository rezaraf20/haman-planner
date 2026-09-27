<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanLimitReached;
use App\Http\Controllers\Controller;
use App\Services\AI\AIPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AIPlannerController extends Controller
{
    public function __invoke(Request $request, AIPlannerService $planner): JsonResponse
    {
        $data = $request->validate(['focus' => 'nullable|string|max:500']);
        try {
            return response()->json($planner->recommend($data));
        } catch (PlanLimitReached $e) {
            throw $e; // rendered as 402 with an upgrade link
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => __('assistant.unavailable'), 'grounded' => false], 503);
        }
    }
}
