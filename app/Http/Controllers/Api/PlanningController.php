<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlanProposal;
use App\Models\Review;
use App\Services\Planner\PlanApplier;
use App\Services\Planner\PlanInsightsService;
use App\Services\Planner\PlanningAssistantService;
use App\Services\Planner\SmartReschedulingService;
use App\Services\Planner\TaskRanker;
use App\Services\Planner\WeeklyReviewService;
use App\Support\LocalDate;
use App\Support\PlanningIntent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Haman AI planning: proposals (never applied without confirmation), what-now, insights, weekly review. */
final class PlanningController extends Controller
{
    public function __construct(private readonly PlanningAssistantService $assistant) {}

    public function propose(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(SmartReschedulingService::KINDS)],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $kind = $data['kind'] ?? PlanningIntent::detect((string) ($data['message'] ?? '')) ?? 'day';
        if ($kind === 'now') {
            return $this->whatNow($request, app(TaskRanker::class));
        }
        $proposal = $this->assistant->generate($request->user(), $kind, $data['message'] ?? null);
        return response()->json($this->assistant->present($proposal, $request->user()), 201);
    }

    public function show(Request $request, PlanProposal $planProposal): JsonResponse
    {
        return response()->json($this->assistant->present($planProposal, $request->user()));
    }

    public function apply(Request $request, PlanProposal $planProposal, PlanApplier $applier): JsonResponse
    {
        $data = $request->validate(['actions' => ['required', 'array', 'min:1', 'max:60'], 'actions.*' => ['string', 'max:10']]);
        if (!$planProposal->isOpen()) {
            return response()->json(['message' => __('planning.not_open')], 409);
        }
        $results = $applier->apply($request->user(), $planProposal, $data['actions']);
        return response()->json(['results' => $results] + $this->assistant->present($planProposal->refresh(), $request->user()));
    }

    public function dismiss(PlanProposal $planProposal): JsonResponse
    {
        if ($planProposal->status === 'pending') {
            $planProposal->update(['status' => 'dismissed']);
        }
        return response()->json(['status' => $planProposal->status]);
    }

    public function whatNow(Request $request, TaskRanker $ranker): JsonResponse
    {
        $user = $request->user();
        $r = $ranker->whatNow($user);
        $loc = $user->preferredLocale();
        $tz = $user->preferredTimezone();
        foreach ($r['ranked'] as &$t) {
            $t['reasons_text'] = array_map(fn ($x) => $this->assistant->reasonText($x, $loc, $tz), $t['reasons']);
        }
        unset($t);
        $r['remaining_text'] = \App\Services\Planner\SchedulingService::duration($r['remaining_work_minutes_today'], $loc);
        $r['kind'] = 'now';
        return response()->json($r);
    }

    public function insights(Request $request, PlanInsightsService $insights): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:365']])['days'] ?? 90);
        $user = $request->user();
        $loc = $user->preferredLocale();
        $pva = $insights->planVsActual($user, $days);
        $pva['insights_text'] = array_map(fn ($i) => PlanInsightsService::text($i, $loc), $pva['insights']);
        $patterns = $insights->failurePatterns($user, CarbonImmutable::now()->subDays(30), CarbonImmutable::now());
        $patterns['top_reasons_text'] = array_map(fn ($r) => __('planner.failure_reason.'.$r['code'], [], $loc), $patterns['top_reasons']);
        $patterns['most_common_missed_text'] = $patterns['missed']['most_common'] ? __('planner.failure_reason.'.$patterns['missed']['most_common']['code'], [], $loc) : null;
        return response()->json([
            'plan_vs_actual' => $pva,
            'failure_patterns' => $patterns,
            'not_enough_history' => !$pva['enough_history'] ? __('insights.not_enough', ['n' => LocalDate::number(PlanInsightsService::minSamples(), $loc)], $loc) : null,
        ]);
    }

    public function weeklyReview(Request $request, WeeklyReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['week_start' => ['nullable', 'date']]);
        $user = $request->user();
        $start = isset($data['week_start']) ? $reviews->weekStart($user, CarbonImmutable::parse($data['week_start'], $user->preferredTimezone())) : null;
        $review = $reviews->generate($user, $start);
        return response()->json($reviews->present($review, $user), 201);
    }

    public function showReview(Request $request, Review $review, WeeklyReviewService $reviews): JsonResponse
    {
        return response()->json($reviews->present($review, $request->user()));
    }
}
