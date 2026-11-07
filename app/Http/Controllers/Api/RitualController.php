<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planner\RitualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Morning planning and evening shutdown (optional, skippable). */
final class RitualController extends Controller
{
    public function __construct(private readonly RitualService $rituals) {}

    public function index(Request $request): JsonResponse
    {
        $days = max(1, min(60, (int) $request->query('days', 14)));
        return response()->json(['prompt' => $this->rituals->prompt($request->user()), 'data' => $this->rituals->history($request->user(), $days)]);
    }

    public function morning(Request $request): JsonResponse
    {
        return response()->json($this->rituals->morning($request->user()));
    }

    public function saveMorning(Request $request): JsonResponse
    {
        $r = $this->rituals->saveMorning($request->user(), $request->only(['energy', 'intention', 'top_task_ids', 'moves']));
        return response()->json(['ok' => true, 'id' => $r->id, 'message' => __('rituals.morning_saved')]);
    }

    public function evening(Request $request): JsonResponse
    {
        return response()->json($this->rituals->evening($request->user()));
    }

    public function saveEvening(Request $request): JsonResponse
    {
        $r = $this->rituals->saveEvening($request->user(), $request->only(['mood', 'energy', 'wins', 'notes', 'tomorrow_task_ids', 'moves']));
        return response()->json(['ok' => true, 'id' => $r->id, 'summary' => $r->summary, 'message' => __('rituals.evening_saved')]);
    }

    public function skip(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, \App\Models\DayRitual::KINDS, true), 404);
        $r = $this->rituals->skip($request->user(), $kind);
        return response()->json(['ok' => true, 'status' => $r->status]);
    }
}
