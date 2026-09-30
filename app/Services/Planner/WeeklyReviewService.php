<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Exceptions\PlanLimitReached;
use App\Models\ActivityLog;
use App\Models\AiInteraction;
use App\Models\ExecutionLog;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\AIPlannerService;
use App\Services\AI\AIProviderFactory;
use App\Services\Billing\Entitlements;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Weekly review from the user's recorded data: completed, missed, rescheduled, planned vs actual
 * hours, top blockers, projects needing attention and rule-based recommendations. An optional AI
 * summary is added on top — it never replaces the metrics, which are always stored and shown.
 */
final class WeeklyReviewService
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly PlanInsightsService $insights,
        private readonly Entitlements $entitlements,
    ) {}

    /** Start of the user's week containing $day (Saturday for Persian, Monday for English). */
    public function weekStart(User $user, ?CarbonImmutable $day = null): CarbonImmutable
    {
        $day = ($day ?? CarbonImmutable::now())->setTimezone($user->preferredTimezone())->startOfDay();
        $shift = $user->preferredLocale() === 'fa' ? ($day->dayOfWeek + 1) % 7 : ($day->dayOfWeek + 6) % 7;
        return $day->subDays($shift);
    }

    public function metrics(User $user, CarbonImmutable $start): array
    {
        $end = $start->addDays(7);
        [$s, $e] = [LocalDate::db($start), LocalDate::db($end)];
        $completed = Task::query()->ownedBy($user->id)->where('status', 'completed')->whereBetween('completed_at', [$s, $e])->count();
        $missed = Task::query()->ownedBy($user->id)->whereNotNull('deadline')->whereBetween('deadline', [$s, LocalDate::db(min($end, CarbonImmutable::now()))])
            ->where('status', '!=', 'cancelled')->get(['id', 'deadline', 'completed_at', 'project_id'])
            ->filter(fn ($t) => $t->completed_at === null || $t->completed_at->gt($t->deadline));
        $rescheduled = ActivityLog::query()->ownedBy($user->id)->where('action', 'rescheduled')->whereBetween('created_at', [$s, $e])->distinct('entity_id')->count('entity_id');
        $skipped = ActivityLog::query()->ownedBy($user->id)->where('action', 'occurrence_skipped')->whereBetween('created_at', [$s, $e])->count();

        $planned = 0;
        for ($d = $start; $d->lt($end); $d = $d->addDay()) {
            $planned += $this->scheduling->dayCapacity($user, $d)['scheduled_minutes'];
        }
        $actual = (int) ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [$s, $e])->sum('duration_minutes');
        $patterns = $this->insights->failurePatterns($user, $start, $end);

        // Projects needing attention: missed work this week or overdue open tasks.
        $overdue = Task::query()->ownedBy($user->id)->whereNotNull('project_id')->whereNotIn('status', ['completed', 'cancelled'])
            ->where('deadline', '<', LocalDate::db(CarbonImmutable::now()))->selectRaw('project_id, count(*) as n')->groupBy('project_id')->pluck('n', 'project_id');
        $missedByProject = $missed->whereNotNull('project_id')->countBy('project_id');
        $ids = $overdue->keys()->merge($missedByProject->keys())->unique()->all();
        $names = Project::query()->ownedBy($user->id)->whereIn('id', $ids)->pluck('title', 'id');
        $projects = collect($ids)->map(fn ($id) => ['project_id' => (int) $id, 'title' => (string) ($names[$id] ?? '#'.$id), 'overdue' => (int) ($overdue[$id] ?? 0), 'missed' => (int) ($missedByProject[$id] ?? 0)])
            ->sortByDesc(fn ($p) => $p['overdue'] + $p['missed'])->take(5)->values()->all();

        return [
            'week_start' => $start->toDateString(), 'week_end' => $end->subDay()->toDateString(),
            'completed' => $completed, 'missed' => $missed->count(), 'rescheduled' => $rescheduled, 'skipped_occurrences' => $skipped,
            'planned_minutes' => $planned, 'actual_minutes' => $actual,
            'blockers' => $patterns['top_reasons'], 'blockers_enough_data' => $patterns['enough_data'],
            'most_common_missed_reason' => $patterns['missed']['most_common'],
            'projects_attention' => $projects,
        ];
    }

    /** @return list<array{key:string,params:array}> */
    public function recommendations(User $user, array $m): array
    {
        $out = [];
        if ($m['planned_minutes'] > 0 && $m['actual_minutes'] > $m['planned_minutes'] * 1.15) {
            $out[] = ['key' => 'plan_more_time', 'params' => ['percent' => (int) round(($m['actual_minutes'] / $m['planned_minutes'] - 1) * 100)]];
        }
        if ($m['missed'] >= 3) {
            $out[] = ['key' => 'fewer_commitments', 'params' => ['count' => $m['missed']]];
        }
        if ($m['rescheduled'] >= 5) {
            $out[] = ['key' => 'too_many_moves', 'params' => ['count' => $m['rescheduled']]];
        }
        if ($m['blockers_enough_data'] && isset($m['blockers'][0])) {
            $out[] = ['key' => 'address_blocker', 'params' => ['reason' => $m['blockers'][0]['code']]];
        }
        if (isset($m['projects_attention'][0])) {
            $out[] = ['key' => 'project_attention', 'params' => ['project' => $m['projects_attention'][0]['title']]];
        }
        foreach ($this->insights->planVsActual($user, 90)['insights'] as $i) {
            if ($i['key'] === 'estimate_under') {
                $out[] = ['key' => 'pad_estimates', 'params' => ['low' => $i['params']['low'], 'high' => $i['params']['high']]];
                break;
            }
        }
        if ($out === []) {
            $out[] = ['key' => 'keep_going', 'params' => []];
        }
        return $out;
    }

    /** Build (or rebuild) the weekly review for the week starting $start. */
    public function generate(User $user, ?CarbonImmutable $start = null, bool $withAi = true): Review
    {
        $start ??= $this->weekStart($user, CarbonImmutable::now()->subWeek());
        $m = $this->metrics($user, $start);
        $recs = $this->recommendations($user, $m);
        $summary = $withAi ? $this->aiSummary($user, $m, $recs) : null;

        $review = Review::query()->ownedBy($user->id)->where('type', 'weekly')->where('period_start', $start->toDateString())->first()
            ?? new Review(['user_id' => $user->id, 'type' => 'weekly', 'period_start' => $start->toDateString()]);
        $review->fill([
            'period_end' => $m['week_end'],
            'summary' => __('weekly.summary_line', [
                'completed' => LocalDate::number($m['completed'], $user->preferredLocale()), 'missed' => LocalDate::number($m['missed'], $user->preferredLocale()),
                'planned' => SchedulingService::duration($m['planned_minutes'], $user->preferredLocale()), 'actual' => SchedulingService::duration($m['actual_minutes'], $user->preferredLocale()),
            ], $user->preferredLocale()),
            'metrics_json' => $m,
            'actions_json' => $recs,
            'ai_summary' => $summary,
        ]);
        $review->user_id = $user->id;
        $review->save();
        return $review;
    }

    private function aiSummary(User $user, array $m, array $recs): ?string
    {
        if ($user->preference('ai_enabled') === false || !$this->entitlements->canUse($user, 'ai_planner')
            || !$this->entitlements->canUse($user, 'advanced_ai_planning') || !AIProviderFactory::available()) {
            return null;
        }
        try {
            $this->entitlements->consume($user, 'ai_requests');
        } catch (PlanLimitReached) {
            return null;
        }
        $requestId = (string) Str::uuid();
        try {
            $r = AIProviderFactory::make()->chat([
                ['role' => 'system', 'content' => 'You are Haman AI. Summarise the user\'s week in at most 90 words using ONLY these metrics and recommendation codes. Do not invent numbers, tasks or causes; if a metric is zero or missing, do not speculate. Return JSON {"summary": string}. Write in '.AIPlannerService::responseLanguage($user).'.'],
                ['role' => 'user', 'content' => json_encode(['metrics' => $m, 'recommendations' => array_column($recs, 'key')], JSON_UNESCAPED_UNICODE)],
            ], ['temperature' => 0.2, 'response_format' => ['type' => 'json_object'], '_feature' => 'weekly_review']);
            $out = json_decode((string) ($r['choices'][0]['message']['content'] ?? ''), true);
            AiInteraction::create(['user_id' => $user->id, 'provider' => AIProviderFactory::lastUsed()['provider'], 'model' => AIProviderFactory::lastUsed()['model'],
                'intent' => 'WEEKLY_REVIEW', 'input_hash' => hash('sha256', $requestId), 'input_payload' => ['week_start' => $m['week_start']],
                'output_payload' => is_array($out) ? $out : null, 'confidence' => null, 'status' => is_array($out) ? 'completed' : 'invalid', 'request_id' => $requestId]);
            $text = is_array($out) && is_string($out['summary'] ?? null) ? trim(strip_tags($out['summary'])) : null;
            return $text !== null && $text !== '' ? mb_substr($text, 0, 1200) : null;
        } catch (\Throwable $e) {
            $this->entitlements->refund($user, 'ai_requests');
            Log::warning('Weekly review AI summary failed', ['request_id' => $requestId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Localized, display-ready review (metrics always included). */
    public function present(Review $r, User $user): array
    {
        $loc = $user->preferredLocale();
        $m = (array) $r->metrics_json;
        return [
            'id' => $r->id, 'type' => $r->type, 'period_start' => $r->period_start?->toDateString(), 'period_end' => $r->period_end?->toDateString(),
            'summary' => $r->summary, 'ai_summary' => $r->ai_summary, 'metrics' => $m,
            'metrics_text' => ['planned' => SchedulingService::duration((int) ($m['planned_minutes'] ?? 0), $loc), 'actual' => SchedulingService::duration((int) ($m['actual_minutes'] ?? 0), $loc)],
            'blockers_text' => array_map(fn ($b) => __('planner.failure_reason.'.$b['code'], [], $loc).' — '.LocalDate::number($b['count'], $loc), (array) ($m['blockers'] ?? [])),
            'recommendations_text' => array_map(fn ($x) => __('weekly.rec.'.$x['key'], array_map(fn ($v) => $x['key'] === 'address_blocker' && is_string($v) ? __('planner.failure_reason.'.$v, [], $loc) : (is_int($v) ? LocalDate::number($v, $loc) : $v), $x['params']), $loc), (array) $r->actions_json),
        ];
    }
}
