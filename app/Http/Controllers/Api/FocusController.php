<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FocusSession;
use App\Services\Planner\FocusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FocusController extends Controller
{
    public function __construct(private readonly FocusService $focus) {}

    /** Current session (if any), settings and today's numbers — everything the timer screen needs. */
    public function state(Request $request): JsonResponse
    {
        return response()->json([
            'current' => $this->focus->current(),
            'settings' => FocusService::settings($request->user()),
            'stats' => $this->focus->stats($request->user(), 7),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'task_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
            'planned_minutes' => 'required|integer|min:1|max:240',
            'client_uuid' => 'nullable|uuid',
        ]);
        $session = $this->focus->start($request->user(), $data);
        return response()->json($session, $session->wasRecentlyCreated ? 201 : 200);
    }

    public function pause(FocusSession $session): JsonResponse { return response()->json($this->focus->pause($session)); }
    public function resume(FocusSession $session): JsonResponse { return response()->json($this->focus->resume($session)); }
    public function cancel(FocusSession $session): JsonResponse { return response()->json($this->focus->cancel($session)); }

    public function finish(Request $request, FocusSession $session): JsonResponse
    {
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $done = $this->focus->finish($session, $data['note'] ?? null);
        if ($done->status === 'completed') \App\Services\Analytics\ProductEvents::record($request->user(), \App\Services\Analytics\ProductEvents::FIRST_FOCUS_SESSION, [], true);
        return response()->json($done);
    }

    public function sessions(Request $request): JsonResponse
    {
        $per = (int) $request->validate(['per_page' => 'nullable|integer|min:1|max:100'])['per_page'] ?: 30;
        return response()->json(FocusSession::query()->with(['task:id,title', 'project:id,title'])
            ->whereNotIn('status', FocusSession::ACTIVE)->latest('started_at')->paginate($per));
    }

    public function stats(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => 'nullable|integer|min:1|max:90'])['days'] ?? 7);
        return response()->json($this->focus->stats($request->user(), $days));
    }

    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['preferences' => array_merge((array) $user->preferences, FocusService::validateSettings($request->all()))]);
        return response()->json(FocusService::settings($user->refresh()));
    }
}
