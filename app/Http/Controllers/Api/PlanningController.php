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
        $projects = \App\Models\Project::query()->whereIn('id', array_filter(array_column($r['ranked'], 'project_id')))->pluck('title', 'id');
        foreach ($r['ranked'] as &$t) {
            $t['reasons_text'] = array_map(fn ($x) => $this->assistant->reasonText($x, $loc, $tz), $t['reasons']);
            $t['project_title'] = isset($t['project_id']) ? ($projects[$t['project_id']] ?? null) : null;
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

    /**
     * Numbers for the "Today" dashboard, all in the user's timezone and all from real records:
     * tasks done/remaining today, estimated vs logged minutes, active goal progress, and at most
     * one planning insight — only when there are enough days of data to be meaningful.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $loc = $user->preferredLocale();
        $now = CarbonImmutable::now($user->preferredTimezone());
        [$s, $e] = [LocalDate::db($now->startOfDay()), LocalDate::db($now->endOfDay())];
        $tasks = fn () => \App\Models\Task::query()->ownedBy($user->id);

        $doneToday = $tasks()->where('status', 'completed')->whereBetween('completed_at', [$s, $e]);
        $remaining = $tasks()->whereNotIn('status', ['completed', 'cancelled'])
            ->where(fn ($q) => $q->whereBetween('deadline', [$s, $e])->orWhereBetween('planned_start', [$s, $e])
                ->orWhereIn('id', \App\Models\ScheduleBlock::query()->ownedBy($user->id)->whereNotNull('task_id')->whereBetween('starts_at', [$s, $e])->select('task_id')));
        $done = (clone $doneToday)->count();
        $left = (clone $remaining)->count();
        $estimated = (int) (clone $remaining)->sum('estimated_minutes') + (int) (clone $doneToday)->sum('estimated_minutes');
        $logged = (int) \App\Models\ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [$s, $e])->sum('duration_minutes');
        $overdue = $tasks()->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('deadline')->where('deadline', '<', $s)->count();

        $goals = \App\Models\Goal::query()->ownedBy($user->id)->open()
            ->orderByRaw('target_date asc nulls last')->orderByDesc('importance')->limit(4)
            ->get(['id', 'title', 'progress', 'target_date', 'status']);

        // Planning insight: last 7 full days, only days that had scheduled work and logged work.
        // Past days do not change, so the result is cached per user and day.
        $insight = \Illuminate\Support\Facades\Cache::remember('dash-insight:'.$user->id.':'.$now->toDateString(), 3600, function () use ($user, $now, $loc) {
            $scheduling = app(\App\Services\Planner\SchedulingService::class);
            $days = 0; $plannedSum = 0; $loggedSum = 0;
            for ($d = $now->startOfDay()->subDays(7); $d->lt($now->startOfDay()); $d = $d->addDay()) {
                $p = $scheduling->dayCapacity($user, $d)['scheduled_minutes'];
                $l = (int) \App\Models\ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [LocalDate::db($d), LocalDate::db($d->endOfDay())])->sum('duration_minutes');
                if ($p > 0 && $l > 0) { $days++; $plannedSum += $p; $loggedSum += $l; }
            }
            if ($days >= 3 && $plannedSum > $loggedSum * 1.25) {
                return ['type' => 'overplanning', 'days' => $days,
                    'planned_text' => \App\Services\Planner\SchedulingService::duration((int) round($plannedSum / $days), $loc),
                    'actual_text' => \App\Services\Planner\SchedulingService::duration((int) round($loggedSum / $days), $loc)];
            }
            return null;
        });

        return response()->json([
            'completed' => $done, 'remaining' => $left, 'overdue' => $overdue,
            'percent' => $done + $left > 0 ? (int) round($done / ($done + $left) * 100) : null,
            'estimated_minutes' => $estimated, 'logged_minutes' => $logged,
            'estimated_text' => \App\Services\Planner\SchedulingService::duration($estimated, $loc),
            'logged_text' => \App\Services\Planner\SchedulingService::duration($logged, $loc),
            'goals' => $goals,
            'insight' => $insight,
            'layout' => self::dashboardLayout($user),
        ]);
    }

    public const WIDGETS = ['now', 'progress', 'schedule', 'priorities', 'focus', 'habits', 'goals', 'week', 'insight'];

    public static function dashboardLayout(\App\Models\User $user): array
    {
        $saved = (array) ($user->preference('dashboard_layout') ?? []);
        $order = array_values(array_filter((array) ($saved['order'] ?? []), fn ($w) => in_array($w, self::WIDGETS, true)));
        foreach (self::WIDGETS as $w) if (!in_array($w, $order, true)) $order[] = $w; // new widgets appear automatically
        return ['order' => $order, 'hidden' => array_values(array_intersect((array) ($saved['hidden'] ?? []), self::WIDGETS))];
    }

    public function saveLayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order' => 'required|array|max:20', 'order.*' => ['string', \Illuminate\Validation\Rule::in(self::WIDGETS)],
            'hidden' => 'present|array|max:20', 'hidden.*' => ['string', \Illuminate\Validation\Rule::in(self::WIDGETS)],
        ]);
        $user = $request->user();
        $user->update(['preferences' => array_merge((array) $user->preferences, ['dashboard_layout' => ['order' => array_values(array_unique($data['order'])), 'hidden' => array_values(array_unique($data['hidden']))]])]);
        return response()->json(self::dashboardLayout($user->refresh()));
    }

    public function insights(Request $request, PlanInsightsService $insights): JsonResponse
    {
        $asked = (int) ($request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:365']])['days'] ?? 90);
        $user = $request->user();
        $days = app(\App\Services\Billing\Entitlements::class)->historyDays($user, $asked);
        $loc = $user->preferredLocale();
        $pva = $insights->planVsActual($user, $days);
        $pva['insights_text'] = array_map(fn ($i) => PlanInsightsService::text($i, $loc), $pva['insights']);
        $patterns = $insights->failurePatterns($user, CarbonImmutable::now()->subDays(min(30, $days)), CarbonImmutable::now());
        $patterns['top_reasons_text'] = array_map(fn ($r) => __('planner.failure_reason.'.$r['code'], [], $loc), $patterns['top_reasons']);
        $patterns['most_common_missed_text'] = $patterns['missed']['most_common'] ? __('planner.failure_reason.'.$patterns['missed']['most_common']['code'], [], $loc) : null;
        return response()->json([
            'history_days' => $days,
            'history_limited' => $days < $asked,
            'upgrade_url' => $days < $asked ? route('billing.index') : null,
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
        $out = $reviews->present($review, $user);
        $out['ai_upgrade_url'] = empty($out['ai_summary']) && app(\App\Services\Planner\PlanningAssistantService::class)->needsUpgradeForAi($user) ? route('billing.index') : null;
        return response()->json($out, 201);
    }

    public function showReview(Request $request, Review $review, WeeklyReviewService $reviews): JsonResponse
    {
        return response()->json($reviews->present($review, $request->user()));
    }
}
