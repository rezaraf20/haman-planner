<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\DayRitual;
use App\Models\ExecutionLog;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Morning planning and evening shutdown. Both are optional and skippable. Everything shown is read
 * from the user's own data; the only changes they make are the ones the user picks explicitly in
 * the wizard (move a task to today / tomorrow / a date, note a reason). Deadlines are never changed.
 */
final class RitualService
{
    private const OPEN = ['inbox', 'planned', 'ready', 'in_progress', 'deferred', 'waiting', 'blocked'];

    public function __construct(
        private readonly TaskRanker $ranker,
        private readonly SchedulingService $scheduling,
        private readonly ActivityLogger $activity,
        private readonly PlannerService $planner,
    ) {}

    public function today(User $user): CarbonImmutable
    {
        return CarbonImmutable::now($user->preferredTimezone())->startOfDay();
    }

    public function find(User $user, CarbonImmutable $day, string $kind): ?DayRitual
    {
        return DayRitual::query()->ownedBy($user->id)->where('kind', $kind)->whereDate('ritual_date', $day->toDateString())->first();
    }

    /** Which ritual (if any) the home page should offer right now. */
    public function prompt(User $user): ?string
    {
        if ($user->preference('rituals_enabled') === false) return null;
        $now = CarbonImmutable::now($user->preferredTimezone());
        $day = $now->startOfDay();
        $window = $this->scheduling->window($user, $day);
        if (!$window) return null; // day off: no nudges
        $morning = $this->find($user, $day, 'morning');
        $evening = $this->find($user, $day, 'evening');
        $endMinus = $window[1]->subHour();
        if (!$evening && $now->gte($endMinus)) return 'evening';
        if (!$morning && $now->lt($window[0]->addHours(4)) && $now->lt($endMinus)) return 'morning';
        return null;
    }

    // ------------------------------------------------------------------ morning

    public function morning(User $user): array
    {
        $day = $this->today($user);
        [$s, $e] = [LocalDate::db($day), LocalDate::db($day->endOfDay())];
        $yesterdayEvening = $this->find($user, $day->subDay(), 'evening');

        // Left from before: open work that was planned or due before today.
        $leftovers = $this->open($user)->where(fn ($q) => $q->where('planned_start', '<', $s)->orWhere('deadline', '<', $s))
            ->orderBy('deadline')->limit(15)->get();
        $wn = $this->ranker->whatNow($user, 10);
        $chosenYesterday = $this->openIds($user, (array) ($yesterdayEvening?->tomorrow_task_ids ?? []));
        $todayIds = $this->open($user)->where(fn ($q) => $q->whereBetween('deadline', [$s, $e])->orWhereBetween('planned_start', [$s, $e]))->pluck('id')->all();
        $ranked = array_column($wn['ranked'], 'id');

        $candidateIds = array_values(array_unique(array_merge($chosenYesterday, $todayIds, $ranked)));
        $candidates = $this->present($user, Task::query()->ownedBy($user->id)->whereIn('id', array_slice($candidateIds, 0, 15))->get(), $candidateIds);
        $existing = $this->find($user, $day, 'morning');
        $suggested = $existing?->top_task_ids ?: ($chosenYesterday ?: array_slice($ranked, 0, 3));

        return [
            'date' => $day->toDateString(),
            'ritual' => $existing ? $this->ritual($existing) : null,
            'from_yesterday' => $chosenYesterday,
            'leftovers' => $this->present($user, $leftovers),
            'candidates' => $candidates,
            'suggested_top' => array_values(array_slice($this->openIds($user, $suggested), 0, 3)),
            'capacity' => $this->capacity($user, $day),
            'yesterday' => $this->yesterday($user, $day),
        ];
    }

    public function saveMorning(User $user, array $in): DayRitual
    {
        $d = validator($in, [
            'energy' => 'nullable|integer|min:1|max:5',
            'intention' => 'nullable|string|max:500',
            'top_task_ids' => 'nullable|array|max:3', 'top_task_ids.*' => 'integer',
            'moves' => 'nullable|array|max:30',
            'moves.*.task_id' => 'required|integer',
            'moves.*.action' => 'required|in:today,tomorrow,date,keep',
            'moves.*.date' => 'nullable|required_if:moves.*.action,date|date_format:Y-m-d',
        ])->validate();
        $day = $this->today($user);
        $top = $this->ownedOpen($user, (array) ($d['top_task_ids'] ?? []), 'top_task_ids');

        return DB::transaction(function () use ($user, $d, $day, $top) {
            $moves = $this->applyMoves($user, (array) ($d['moves'] ?? []), $day);
            $r = $this->upsert($user, $day, 'morning', [
                'status' => 'completed', 'energy' => $d['energy'] ?? null, 'intention' => $this->clean($d['intention'] ?? null),
                'top_task_ids' => $top, 'moves' => $moves, 'completed_at' => now(),
            ]);
            \App\Services\Analytics\ProductEvents::record($user, \App\Services\Analytics\ProductEvents::FIRST_MORNING_PLAN, [], true);
            return $r;
        });
    }

    // ------------------------------------------------------------------ evening

    public function evening(User $user): array
    {
        $day = $this->today($user);
        [$s, $e] = [LocalDate::db($day), LocalDate::db($day->endOfDay())];
        $morning = $this->find($user, $day, 'morning');
        $top = (array) ($morning?->top_task_ids ?? []);

        $completed = Task::query()->ownedBy($user->id)->where('status', 'completed')->whereBetween('completed_at', [$s, $e])->latest('completed_at')->limit(30)->get();
        $unfinished = $this->open($user)->where(fn ($q) => $q->whereBetween('deadline', [$s, $e])->orWhereBetween('planned_start', [$s, $e])->orWhereIn('id', $top ?: [0]))
            ->orderBy('deadline')->limit(20)->get();
        $tomorrow = $day->addDay();
        $dueTomorrow = $this->open($user)->whereBetween('deadline', [LocalDate::db($tomorrow), LocalDate::db($tomorrow->endOfDay())])->pluck('id')->all();
        $ranked = array_column($this->ranker->whatNow($user, 10)['ranked'], 'id');
        $unfinishedIds = $unfinished->pluck('id')->all();
        $candidateIds = array_values(array_unique(array_merge($unfinishedIds, $dueTomorrow, $ranked)));
        $existing = $this->find($user, $day, 'evening');

        return [
            'date' => $day->toDateString(),
            'ritual' => $existing ? $this->ritual($existing) : null,
            'morning' => $morning ? $this->ritual($morning) : null,
            'summary' => $this->daySummary($user, $day, $top),
            'completed' => $this->present($user, $completed),
            'unfinished' => $this->present($user, $unfinished),
            'candidates' => $this->present($user, Task::query()->ownedBy($user->id)->whereIn('id', array_slice($candidateIds, 0, 15))->get(), $candidateIds),
            'suggested_tomorrow' => array_values(array_slice($existing?->tomorrow_task_ids ?: array_values(array_unique(array_merge($dueTomorrow, $unfinishedIds, $ranked))), 0, 3)),
            'reasons' => array_keys((array) __('planner.failure_reason')),
        ];
    }

    public function saveEvening(User $user, array $in): DayRitual
    {
        $reasons = array_keys((array) __('planner.failure_reason'));
        $d = validator($in, [
            'mood' => 'nullable|integer|min:1|max:5',
            'energy' => 'nullable|integer|min:1|max:5',
            'wins' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'tomorrow_task_ids' => 'nullable|array|max:3', 'tomorrow_task_ids.*' => 'integer',
            'moves' => 'nullable|array|max:30',
            'moves.*.task_id' => 'required|integer',
            'moves.*.action' => 'required|in:tomorrow,date,keep',
            'moves.*.date' => 'nullable|required_if:moves.*.action,date|date_format:Y-m-d',
            'moves.*.reason' => ['nullable', 'string', \Illuminate\Validation\Rule::in($reasons)],
        ])->validate();
        $day = $this->today($user);
        $tomorrowIds = $this->ownedOpen($user, (array) ($d['tomorrow_task_ids'] ?? []), 'tomorrow_task_ids');

        return DB::transaction(function () use ($user, $d, $day, $tomorrowIds) {
            $moves = $this->applyMoves($user, (array) ($d['moves'] ?? []), $day);
            $morning = $this->find($user, $day, 'morning');
            $r = $this->upsert($user, $day, 'evening', [
                'status' => 'completed', 'mood' => $d['mood'] ?? null, 'energy' => $d['energy'] ?? null,
                'wins' => $this->clean($d['wins'] ?? null), 'notes' => $this->clean($d['notes'] ?? null),
                'tomorrow_task_ids' => $tomorrowIds, 'moves' => $moves, 'completed_at' => now(),
                'summary' => $this->daySummary($user, $day, (array) ($morning?->top_task_ids ?? [])),
            ]);
            \App\Services\Analytics\ProductEvents::record($user, \App\Services\Analytics\ProductEvents::FIRST_SHUTDOWN, [], true);
            return $r;
        });
    }

    public function skip(User $user, string $kind): DayRitual
    {
        $day = $this->today($user);
        $existing = $this->find($user, $day, $kind);
        if ($existing) return $existing; // never overwrite a finished ritual with "skipped"
        return $this->upsert($user, $day, $kind, ['status' => 'skipped']);
    }

    /** @return list<array> recent rituals for the history list */
    public function history(User $user, int $days = 14): array
    {
        $from = $this->today($user)->subDays($days - 1)->toDateString();
        return DayRitual::query()->ownedBy($user->id)->whereDate('ritual_date', '>=', $from)
            ->orderByDesc('ritual_date')->orderBy('kind')->get()->map(fn ($r) => $this->ritual($r))->all();
    }

    // ------------------------------------------------------------------ weekly review

    /** Ritual facts for a week. Only real records are counted; nothing is estimated. */
    public function weekStats(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DayRitual::query()->ownedBy($user->id)->whereDate('ritual_date', '>=', $start->toDateString())
            ->whereDate('ritual_date', '<', $end->toDateString())->orderBy('ritual_date')->get();
        $done = $rows->where('status', 'completed');
        $mornings = $done->where('kind', 'morning');
        $evenings = $done->where('kind', 'evening');

        // Top-3 follow-through: chosen in the morning and completed by the end of that day.
        $picked = 0; $kept = 0;
        foreach ($mornings as $m) {
            $ids = (array) $m->top_task_ids;
            if (!$ids) continue;
            $date = CarbonImmutable::parse($m->ritual_date->toDateString(), $user->preferredTimezone());
            $picked += count($ids);
            $kept += Task::query()->ownedBy($user->id)->whereIn('id', $ids)->where('status', 'completed')
                ->where('completed_at', '<=', LocalDate::db($date->endOfDay()))->count();
        }
        $avg = fn (Collection $c, string $k) => ($v = $c->pluck($k)->filter()) ->isNotEmpty() ? round($v->avg(), 1) : null;

        return [
            'mornings' => $mornings->count(),
            'evenings' => $evenings->count(),
            'skipped' => $rows->where('status', 'skipped')->count(),
            'avg_energy' => $avg($done, 'energy'),
            'avg_mood' => $avg($evenings, 'mood'),
            'top3_picked' => $picked,
            'top3_done' => $kept,
            'top3_rate' => $picked ? (int) round($kept / $picked * 100) : null,
            'wins' => $evenings->filter(fn ($r) => filled($r->wins))->map(fn ($r) => ['date' => $r->ritual_date->toDateString(), 'text' => (string) $r->wins])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function open(User $user)
    {
        return Task::query()->ownedBy($user->id)->whereIn('status', self::OPEN);
    }

    /** @return list<int> the given ids that are the user's open tasks, in the given order */
    private function openIds(User $user, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $ok = $this->open($user)->whereIn('id', $ids)->pluck('id')->all();
        return array_values(array_filter($ids, fn ($id) => in_array($id, $ok, true)));
    }

    private function ownedOpen(User $user, array $ids, string $field): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ok = $this->openIds($user, $ids);
        if (count($ok) !== count($ids)) throw ValidationException::withMessages([$field => __('rituals.task_not_found')]);
        return $ok;
    }

    /** Apply the moves the user picked. Only planned_start changes; deadlines are left as they are. */
    private function applyMoves(User $user, array $moves, CarbonImmutable $day): array
    {
        $out = [];
        $tz = $user->preferredTimezone();
        [$h, $m] = array_map('intval', explode(':', (string) $user->preference('work_start') ?: '09:00') + [1 => 0]);
        foreach ($moves as $mv) {
            $task = $this->open($user)->find((int) $mv['task_id']);
            if (!$task) throw ValidationException::withMessages(['moves' => __('rituals.task_not_found')]);
            $action = $mv['action'];
            if (!empty($mv['reason'])) {
                $this->planner->logFailure($task, ['reason' => $mv['reason']]);
                $task->refresh();
            }
            if ($action === 'keep') { $out[] = ['task_id' => $task->id, 'action' => 'keep', 'reason' => $mv['reason'] ?? null]; continue; }
            $target = match ($action) {
                'today' => $day,
                'tomorrow' => $day->addDay(),
                default => CarbonImmutable::createFromFormat('Y-m-d', $mv['date'], $tz)->startOfDay(),
            };
            if ($target->lt($day)) throw ValidationException::withMessages(['moves' => __('rituals.past_date')]);
            $start = $target->setTime($h, $m);
            $before = ['planned_start' => $task->planned_start?->toIso8601String(), 'planned_end' => $task->planned_end?->toIso8601String()];
            $length = $task->planned_start && $task->planned_end ? $task->planned_start->diffInMinutes($task->planned_end) : null;
            $task->planned_start = $start;
            $task->planned_end = $length !== null ? $start->addMinutes((int) $length) : null;
            if (in_array($task->status, ['inbox', 'deferred'], true)) $task->status = 'planned';
            if ($task->isOccurrence() && $task->recurrence_exception === null && $task->isDirty(['planned_start'])) $task->recurrence_exception = 'moved';
            $changed = $task->isDirty('planned_start');
            $task->save();
            if ($changed) {
                $this->activity->log($before['planned_start'] ? 'rescheduled' : 'scheduled', Task::class, $task->id, $before,
                    ['planned_start' => $start->toIso8601String(), 'source' => 'ritual']);
            }
            $out[] = ['task_id' => $task->id, 'action' => $action, 'date' => $target->toDateString(), 'reason' => $mv['reason'] ?? null];
        }
        return $out;
    }

    private function upsert(User $user, CarbonImmutable $day, string $kind, array $data): DayRitual
    {
        $r = $this->find($user, $day, $kind) ?? new DayRitual(['ritual_date' => $day->toDateString(), 'kind' => $kind]);
        $r->fill($data);
        $r->user_id = $user->id;
        $r->save();
        return $r->refresh();
    }

    private function clean(?string $s): ?string
    {
        $s = $s === null ? null : trim(strip_tags($s));
        return $s === '' ? null : $s;
    }

    private function capacity(User $user, CarbonImmutable $day): array
    {
        $c = $this->scheduling->dayCapacity($user, $day);
        [$s, $e] = [LocalDate::db($day), LocalDate::db($day->endOfDay())];
        $committed = (int) $this->open($user)->where(fn ($q) => $q->whereBetween('deadline', [$s, $e])->orWhereBetween('planned_start', [$s, $e]))->sum('estimated_minutes');
        $loc = $user->preferredLocale();
        return [
            'working' => (bool) ($c['working'] ?? false),
            'usable_minutes' => (int) ($c['usable_minutes'] ?? 0),
            'scheduled_minutes' => (int) ($c['scheduled_minutes'] ?? 0),
            'committed_minutes' => $committed,
            'usable_text' => SchedulingService::duration((int) ($c['usable_minutes'] ?? 0), $loc),
            'committed_text' => SchedulingService::duration($committed, $loc),
            'over' => $committed > (int) ($c['usable_minutes'] ?? 0) && (int) ($c['usable_minutes'] ?? 0) > 0,
        ];
    }

    private function yesterday(User $user, CarbonImmutable $day): array
    {
        $y = $day->subDay();
        [$s, $e] = [LocalDate::db($y), LocalDate::db($y->endOfDay())];
        return [
            'completed' => Task::query()->ownedBy($user->id)->where('status', 'completed')->whereBetween('completed_at', [$s, $e])->count(),
            'logged_text' => SchedulingService::duration((int) ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [$s, $e])->sum('duration_minutes'), $user->preferredLocale()),
        ];
    }

    /** Server-computed facts of a day (never taken from the client). */
    private function daySummary(User $user, CarbonImmutable $day, array $top): array
    {
        [$s, $e] = [LocalDate::db($day), LocalDate::db($day->endOfDay())];
        $loc = $user->preferredLocale();
        $logged = (int) ExecutionLog::query()->ownedBy($user->id)->whereBetween('started_at', [$s, $e])->sum('duration_minutes');
        $focusSeconds = (int) FocusSession::query()->ownedBy($user->id)->where('status', 'completed')->whereBetween('ended_at', [$s, $e])->sum('actual_seconds');
        $habits = app(HabitService::class)->overview($user)['today'];
        $topDone = $top ? Task::query()->ownedBy($user->id)->whereIn('id', $top)->where('status', 'completed')->count() : 0;
        return [
            'completed' => Task::query()->ownedBy($user->id)->where('status', 'completed')->whereBetween('completed_at', [$s, $e])->count(),
            'logged_minutes' => $logged, 'logged_text' => SchedulingService::duration($logged, $loc),
            'focus_minutes' => intdiv($focusSeconds, 60), 'focus_text' => SchedulingService::duration(intdiv($focusSeconds, 60), $loc),
            'habits_done' => (int) $habits['done'], 'habits_due' => (int) $habits['due'],
            'top_total' => count($top), 'top_done' => $topDone,
        ];
    }

    private function ritual(DayRitual $r): array
    {
        return $r->only(['id', 'kind', 'status', 'energy', 'mood', 'intention', 'wins', 'notes', 'top_task_ids', 'tomorrow_task_ids', 'moves', 'summary'])
            + ['date' => $r->ritual_date->toDateString(), 'completed_at' => $r->completed_at?->toIso8601String()];
    }

    /** Compact task rows for the wizard; $order keeps the ranking order when given. */
    private function present(User $user, Collection $tasks, ?array $order = null): array
    {
        $projects = Project::query()->ownedBy($user->id)->whereIn('id', $tasks->pluck('project_id')->filter()->unique()->all())->pluck('title', 'id');
        $rows = $tasks->map(fn (Task $t) => [
            'id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'priority' => $t->priority,
            'deadline' => $t->deadline?->toIso8601String(), 'planned_start' => $t->planned_start?->toIso8601String(),
            'estimated_minutes' => (int) $t->estimated_minutes, 'project_title' => $t->project_id ? ($projects[$t->project_id] ?? null) : null,
            'completed_at' => $t->completed_at?->toIso8601String(),
        ]);
        if ($order) {
            $pos = array_flip($order);
            $rows = $rows->sortBy(fn ($r) => $pos[$r['id']] ?? PHP_INT_MAX);
        }
        return $rows->values()->all();
    }
}
