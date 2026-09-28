<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Exceptions\PlanLimitReached;
use App\Models\ExecutionLog;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates occurrences of recurring tasks lazily — only up to a short horizon ahead
 * (config planner.recurrence_horizon_days) — and implements the series operations.
 *
 * The generation cursor (generated_until) only moves forward, so an occurrence that was
 * deleted or skipped is never recreated.
 */
final class RecurrenceService
{
    private const OPEN = ['inbox', 'planned', 'ready', 'deferred'];

    public function __construct(private readonly ActivityLogger $activity) {}

    public static function horizonDays(): int
    {
        return max(1, (int) config('planner.recurrence_horizon_days', 14));
    }

    /** Today in the series' own timezone. */
    private function localToday(RecurringTask $series): CarbonImmutable
    {
        return CarbonImmutable::now($series->timezone ?: config('app.timezone'))->startOfDay();
    }

    /** Create occurrences up to the horizon (or $until). Returns the number of tasks created. */
    public function generate(RecurringTask $series, ?string $until = null): int
    {
        if (!$series->isActive()) {
            return 0;
        }
        $until ??= $this->localToday($series)->addDays(self::horizonDays())->toDateString();
        $from = $series->generated_until
            ? CarbonImmutable::parse($series->generated_until)->addDay()->toDateString()
            : $series->starts_on->toDateString();
        if ($from > $until) {
            return 0;
        }

        $created = 0;
        foreach ($series->rule()->between($from, $until) as $date) {
            try {
                if ($this->createOccurrence($series, $date)) {
                    $created++;
                }
            } catch (PlanLimitReached $e) {
                // Respect the plan's open-task limit: stop here and retry on the next run.
                Log::info('Recurring task generation paused by plan limit', ['recurring_task_id' => $series->id, 'metric' => $e->metric]);
                $until = CarbonImmutable::parse($date)->subDay()->toDateString();
                break;
            }
        }
        $series->forceFill([
            'generated_until' => max($until, (string) ($series->generated_until?->toDateString() ?? '0000-00-00')),
            'occurrences_generated' => (int) $series->occurrences_generated + $created,
        ])->save();

        return $created;
    }

    /** Scheduler entry point: extend every active series. */
    public function generateAll(): int
    {
        $total = 0;
        RecurringTask::withoutGlobalScopes()->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('generated_until')->orWhere('generated_until', '<', now()->addDays(self::horizonDays())->toDateString()))
            ->orderBy('id')->chunkById(100, function ($chunk) use (&$total): void {
                foreach ($chunk as $series) {
                    try {
                        $total += $this->generate($series);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        return $total;
    }

    private function createOccurrence(RecurringTask $series, string $date): bool
    {
        $exists = Task::withoutGlobalScopes()->where('recurring_task_id', $series->id)->where('occurrence_date', $date)->exists();
        if ($exists) {
            return false;
        }
        [$start, $end, $deadline] = $this->times($series, $date);
        Task::create($series->templateAttributes() + [
            'user_id' => $series->user_id,
            'recurring_task_id' => $series->id,
            'occurrence_date' => $date,
            'status' => 'planned',
            'planned_start' => $start,
            'planned_end' => $end,
            'deadline' => $deadline,
        ]);
        return true;
    }

    /** @return array{0:?Carbon,1:?Carbon,2:Carbon} start, end and deadline for an occurrence date */
    public function times(RecurringTask $series, string $date): array
    {
        $tz = $series->timezone ?: (string) config('app.timezone');
        $start = null;
        $end = null;
        if ($series->time_of_day) {
            $start = Carbon::parse($date.' '.$series->time_of_day, $tz);
            $end = $series->estimated_minutes > 0 ? $start->copy()->addMinutes((int) $series->estimated_minutes) : null;
        }
        $deadline = Carbon::parse($date.' 23:59:00', $tz);
        return [$start, $end, $deadline];
    }

    // ----------------------------------------------------------------- occurrence operations

    public function skip(Task $occurrence): Task
    {
        $before = $occurrence->toArray();
        $occurrence->update(['status' => 'cancelled', 'recurrence_exception' => 'skipped']);
        $this->activity->log('occurrence_skipped', Task::class, $occurrence->id, $before, $occurrence->fresh()->toArray());
        return $occurrence->refresh();
    }

    /** Is this occurrence still exactly as generated (safe to update or replace from the series)? */
    public function isUntouched(Task $t): bool
    {
        return $t->recurrence_exception === null
            && in_array($t->status, self::OPEN, true)
            && (int) $t->actual_minutes === 0
            && !ExecutionLog::withoutGlobalScopes()->where('task_id', $t->id)->exists();
    }

    // ----------------------------------------------------------------- series operations

    /**
     * "Edit all future occurrences": updates the series and every untouched occurrence on or after
     * $fromDate. When the rule or time changes, those untouched future occurrences are replaced so
     * they follow the new rule; completed, started, skipped and individually edited occurrences stay.
     */
    public function updateSeries(RecurringTask $series, array $data, ?string $fromDate = null): RecurringTask
    {
        return DB::transaction(function () use ($series, $data, $fromDate): RecurringTask {
            $fromDate ??= $this->localToday($series)->toDateString();
            $before = $series->toArray();
            $series->fill($data);
            $ruleChanged = $series->isDirty(RecurringTask::RULE_FIELDS);
            $templateChanges = array_intersect_key($series->getDirty(), array_flip(RecurringTask::TEMPLATE_FIELDS));
            $series->save();

            $future = Task::withoutGlobalScopes()->where('recurring_task_id', $series->id)
                ->where('occurrence_date', '>=', $fromDate)->get()
                ->filter(fn (Task $t) => $this->isUntouched($t));

            if ($ruleChanged) {
                foreach ($future as $t) {
                    $t->delete();
                }
                $series->forceFill(['generated_until' => CarbonImmutable::parse($fromDate)->subDay()->toDateString()])->save();
                $this->generate($series->refresh());
            } elseif ($templateChanges !== []) {
                foreach ($future as $t) {
                    $t->update($templateChanges);
                }
            }
            $this->activity->log('recurrence_updated', RecurringTask::class, $series->id, $before, $series->fresh()->toArray());
            return $series->refresh();
        });
    }

    /** Stop the series after $fromDate (default today): untouched later occurrences are removed. */
    public function stop(RecurringTask $series, ?string $fromDate = null): RecurringTask
    {
        return DB::transaction(function () use ($series, $fromDate): RecurringTask {
            $today = $fromDate ?? $this->localToday($series)->toDateString();
            Task::withoutGlobalScopes()->where('recurring_task_id', $series->id)->where('occurrence_date', '>', $today)->get()
                ->filter(fn (Task $t) => $this->isUntouched($t))->each->delete();
            $series->update(['status' => 'stopped', 'stopped_at' => now(), 'ends_on' => $today]);
            $this->activity->log('recurrence_stopped', RecurringTask::class, $series->id, null, ['ends_on' => $today]);
            return $series->refresh();
        });
    }

    /** Dates the series will produce in a window, without creating anything (calendar preview). */
    public function preview(RecurringTask $series, string $from, string $to): array
    {
        return $series->isActive() ? $series->rule()->between($from, $to) : [];
    }

    /** Default working-day set for the "working days" preset. */
    public static function workingDays(User $user): array
    {
        return array_values(array_map('intval', (array) $user->preference('work_days')));
    }
}
