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

    /**
     * Week-to-date progress for the home screen, from recorded data only: tasks planned for this
     * week (deadline or planned start inside it) vs completed, scheduled vs logged time, and what
     * needs attention (overdue, due soon without a slot).
     */
    public function week(Request $request, WeeklyReviewService $weekly): JsonResponse
    {
        $user = $request->user();
        $loc = $user->preferredLocale();
        $tz = $user->preferredTimezone();
        $now = CarbonImmutable::now($tz);
        $start = $weekly->weekStart($user, $now);
        $end = $start->addDays(7);
        [$s, $e] = [LocalDate::db($start), LocalDate::db($end)];

        $inWeek = \App\Models\Task::query()->ownedBy($user->id)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereBetween('deadline', [$s, $e])->orWhereBetween('planned_start', [$s, $e]));
        $total = (clone $inWeek)->count();
        $done = (clone $inWeek)->where('status', 'completed')->count();
        $scheduling = app(\App\Services\Planner\SchedulingService::class);
        $planned = 0;
        for ($d = $start; $d->lt($end); $d = $d->addDay()) {
            $planned += $scheduling->dayCapacity($user, $d)['scheduled_minutes'];
        }
        $actual = (int) \App\Models\ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [$s, $e])->sum('duration_minutes');

        $open = fn () => \App\Models\Task::query()->ownedBy($user->id)->whereNotIn('status', ['completed', 'cancelled']);
        $overdue = $open()->whereNotNull('deadline')->where('deadline', '<', LocalDate::db($now))->orderBy('deadline')->limit(5)->get(['id', 'title', 'deadline', 'priority']);
        $overdueCount = $open()->whereNotNull('deadline')->where('deadline', '<', LocalDate::db($now))->count();
        $soon = $open()->whereNull('planned_start')->whereBetween('deadline', [LocalDate::db($now), LocalDate::db($now->addHours(48))])
            ->orderBy('deadline')->limit(5)->get(['id', 'title', 'deadline', 'priority']);

        return response()->json([
            'week_start' => $start->toDateString(), 'week_end' => $end->subDay()->toDateString(),
            'tasks_total' => $total, 'tasks_completed' => $done,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : null,
            'planned_minutes' => $planned, 'actual_minutes' => $actual,
            'planned_text' => \App\Services\Planner\SchedulingService::duration($planned, $loc),
            'actual_text' => \App\Services\Planner\SchedulingService::duration($actual, $loc),
            'overdue' => $overdue, 'overdue_count' => $overdueCount, 'due_soon_unscheduled' => $soon,
        ]);
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
