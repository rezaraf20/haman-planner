<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\ActivityLog;
use App\Models\ExecutionLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;

/**
 * "Measure → Understand": plan-vs-actual and failure patterns computed only from recorded data.
 *
 * Every insight carries its sample size; nothing is reported below the minimum sample
 * (config planner.insights_min_samples), so small histories say "not enough history yet"
 * instead of pretending to be significant.
 */
final class PlanInsightsService
{
    private const CLOSED = ['completed', 'cancelled'];

    public static function minSamples(): int
    {
        return max(3, (int) config('planner.insights_min_samples', 5));
    }

    /**
     * @return array{period:array,metrics:array,estimation:array,projects:list<array>,insights:list<array>,enough_history:bool}
     */
    public function planVsActual(User $user, int $days = 90): array
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $min = self::minSamples();

        $done = Task::query()->ownedBy($user->id)->where('status', 'completed')
            ->whereBetween('completed_at', [LocalDate::db($from), LocalDate::db($to)])
            ->get(['id', 'project_id', 'estimated_minutes', 'actual_minutes', 'deadline', 'planned_end', 'completed_at']);

        // Estimated vs actual effort (only tasks with both numbers recorded).
        $measured = $done->filter(fn ($t) => (int) $t->estimated_minutes > 0 && (int) $t->actual_minutes > 0);
        $est = (int) $measured->sum('estimated_minutes');
        $act = (int) $measured->sum('actual_minutes');
        $ratio = $est > 0 ? $act / $est : null;
        $ratios = $measured->map(fn ($t) => $t->actual_minutes / $t->estimated_minutes)->sort()->values();

        // Deadline reliability: tasks whose deadline fell in the window.
        $dueTasks = Task::query()->ownedBy($user->id)->whereNotNull('deadline')
            ->whereBetween('deadline', [LocalDate::db($from), LocalDate::db($to)])->where('status', '!=', 'cancelled')
            ->get(['id', 'deadline', 'completed_at', 'status']);
        $onTime = $dueTasks->filter(fn ($t) => $t->completed_at !== null && $t->completed_at->lte($t->deadline))->count();

        // Schedule variance: completion vs planned end.
        $scheduled = $done->filter(fn ($t) => $t->planned_end !== null);
        $variances = $scheduled->map(fn ($t) => $t->planned_end->diffInMinutes($t->completed_at, false));

        $execMinutes = (int) ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [LocalDate::db($from), LocalDate::db($to)])->sum('duration_minutes');

        $metrics = [
            'completed' => $done->count(),
            'estimated_minutes' => $est,
            'actual_minutes' => $act,
            'estimate_sample' => $measured->count(),
            'variance_percent' => $ratio !== null ? (int) round(($ratio - 1) * 100) : null,
            'due_tasks' => $dueTasks->count(),
            'on_time' => $onTime,
            'deadline_reliability_percent' => $dueTasks->count() ? (int) round($onTime / $dueTasks->count() * 100) : null,
            'completion_rate_percent' => $dueTasks->count() ? (int) round($dueTasks->where('status', 'completed')->count() / $dueTasks->count() * 100) : null,
            'schedule_variance_minutes' => $variances->count() ? (int) round($variances->avg()) : null,
            'schedule_sample' => $variances->count(),
            'execution_minutes' => $execMinutes,
        ];

        // Per project: which ones repeatedly run over.
        $projects = [];
        foreach ($measured->whereNotNull('project_id')->groupBy('project_id') as $projectId => $tasks) {
            if ($tasks->count() < $min) continue;
            $pe = (int) $tasks->sum('estimated_minutes');
            $pa = (int) $tasks->sum('actual_minutes');
            $over = $tasks->filter(fn ($t) => $t->actual_minutes > $t->estimated_minutes * 1.2)->count();
            $projects[] = ['project_id' => (int) $projectId, 'sample' => $tasks->count(), 'variance_percent' => (int) round(($pa / max(1, $pe) - 1) * 100), 'overruns' => $over];
        }
        $names = Project::query()->ownedBy($user->id)->whereIn('id', array_column($projects, 'project_id'))->pluck('title', 'id');
        foreach ($projects as &$p) {
            $p['title'] = (string) ($names[$p['project_id']] ?? '#'.$p['project_id']);
        }
        unset($p);

        $insights = [];
        if ($measured->count() >= $min && $ratio !== null) {
            // Interquartile range of per-task ratios gives an honest "usually X–Y%" band.
            $q1 = $ratios[(int) floor(($ratios->count() - 1) * 0.25)];
            $q3 = $ratios[(int) floor(($ratios->count() - 1) * 0.75)];
            if ($ratio >= 1.15) {
                $insights[] = ['key' => 'estimate_under', 'params' => ['low' => max(0, (int) round(($q1 - 1) * 100)), 'high' => (int) round(($q3 - 1) * 100)], 'sample' => $measured->count(), 'level' => 'warn'];
            } elseif ($ratio <= 0.85) {
                $insights[] = ['key' => 'estimate_over', 'params' => ['percent' => (int) round((1 - $ratio) * 100)], 'sample' => $measured->count(), 'level' => 'info'];
            } else {
                $insights[] = ['key' => 'estimate_good', 'params' => [], 'sample' => $measured->count(), 'level' => 'ok'];
            }
        }
        foreach ($projects as $p) {
            if ($p['variance_percent'] >= 30 && $p['overruns'] >= (int) ceil($p['sample'] / 2)) {
                $insights[] = ['key' => 'project_overruns', 'params' => ['project' => $p['title'], 'percent' => $p['variance_percent'], 'count' => $p['overruns']], 'sample' => $p['sample'], 'level' => 'warn'];
            }
        }
        if ($dueTasks->count() >= $min && $metrics['deadline_reliability_percent'] !== null && $metrics['deadline_reliability_percent'] < 70) {
            $insights[] = ['key' => 'deadlines_slip', 'params' => ['percent' => $metrics['deadline_reliability_percent']], 'sample' => $dueTasks->count(), 'level' => 'warn'];
        }
        if ($variances->count() >= $min && $metrics['schedule_variance_minutes'] >= 60) {
            $insights[] = ['key' => 'finish_late', 'params' => ['minutes' => $metrics['schedule_variance_minutes']], 'sample' => $variances->count(), 'level' => 'info'];
        }

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'days' => $days],
            'metrics' => $metrics,
            'estimation' => ['ratio' => $measured->count() >= $min ? round((float) $ratio, 2) : null, 'sample' => $measured->count(), 'min_sample' => $min],
            'projects' => $projects,
            'insights' => $insights,
            'enough_history' => $measured->count() >= $min || $dueTasks->count() >= $min,
        ];
    }

    /** Planning factor from history: multiply estimates by this (1.0 when history is insufficient). */
    public function estimateFactor(User $user): float
    {
        $pva = $this->planVsActual($user, 90);
        $r = $pva['estimation']['ratio'];
        return $r === null ? 1.0 : max(0.8, min(2.0, (float) $r));
    }

    /**
     * What gets in the way, from recorded failure/blocker entries only.
     *
     * @return array{period:array,total:int,enough_data:bool,top_reasons:list<array>,missed:array,overloaded_misses:int}
     */
    public function failurePatterns(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Every time a reason was recorded (history is kept even if the task changed later).
        $events = ActivityLog::query()->ownedBy($user->id)->whereIn('action', ['failure_logged', 'blocker_logged'])
            ->whereBetween('created_at', [LocalDate::db($from), LocalDate::db($to)])->get(['after_json']);
        $counts = [];
        foreach ($events as $e) {
            $reason = (string) (($e->after_json['failure_reason'] ?? null) ?: 'other');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }
        // Tasks carrying a reason that was set outside the logged flows (e.g. edited directly).
        if ($counts === []) {
            foreach (Task::query()->ownedBy($user->id)->whereNotNull('failure_reason')->whereBetween('updated_at', [LocalDate::db($from), LocalDate::db($to)])->pluck('failure_reason') as $r) {
                $counts[(string) $r] = ($counts[(string) $r] ?? 0) + 1;
            }
        }
        $blockerNotes = ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [LocalDate::db($from), LocalDate::db($to)])->whereNotNull('blocker')->where('blocker', '!=', '')->count();
        arsort($counts);
        $total = array_sum($counts);

        // Missed = deadline passed in the window without completion by the deadline.
        $missed = Task::query()->ownedBy($user->id)->whereNotNull('deadline')
            ->whereBetween('deadline', [LocalDate::db($from), LocalDate::db(min($to, CarbonImmutable::now()))])->where('status', '!=', 'cancelled')
            ->get(['id', 'deadline', 'completed_at', 'failure_reason'])
            ->filter(fn ($t) => $t->completed_at === null || $t->completed_at->gt($t->deadline));
        $missedReasons = $missed->whereNotNull('failure_reason')->countBy('failure_reason')->sortDesc();

        // Deadline collisions: missed tasks due on days that were over capacity (recorded plans only).
        $scheduling = app(SchedulingService::class);
        $overloadedMisses = 0;
        foreach ($missed->groupBy(fn ($t) => $t->deadline->setTimezone($user->preferredTimezone())->toDateString())->take(31) as $day => $tasks) {
            $cap = $scheduling->dayCapacity($user, CarbonImmutable::parse($day, $user->preferredTimezone()));
            if ($cap['overload_minutes'] > 0) {
                $overloadedMisses += $tasks->count();
            }
        }

        $min = 3;
        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'total' => $total,
            'blocker_notes' => $blockerNotes,
            'enough_data' => $total >= $min,
            'top_reasons' => collect($counts)->take(5)->map(fn ($n, $code) => ['code' => (string) $code, 'count' => $n, 'share' => $total ? (int) round($n / $total * 100) : 0])->values()->all(),
            'missed' => [
                'count' => $missed->count(),
                'with_reason' => (int) $missedReasons->sum(),
                'most_common' => $missedReasons->count() && $missedReasons->sum() >= $min ? ['code' => (string) $missedReasons->keys()->first(), 'count' => (int) $missedReasons->first()] : null,
            ],
            'overloaded_misses' => $overloadedMisses,
        ];
    }

    /** Human text for an insight, in the user's language. */
    public static function text(array $insight, ?string $locale = null): string
    {
        $params = array_map(fn ($v) => is_int($v) ? LocalDate::number($v, $locale) : $v, $insight['params']);
        return (string) __('insights.'.$insight['key'], $params, $locale)
            .' '.__('insights.sample', ['n' => LocalDate::number($insight['sample'], $locale)], $locale);
    }
}
