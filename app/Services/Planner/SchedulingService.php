<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Domain\Planner\CapacityPlanner;
use App\Models\CalendarEvent;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Time blocking: one place that combines working hours, busy calendar events, time blocks and
 * scheduled tasks into per-day capacity, conflicts and overload warnings.
 *
 * All day boundaries are the user's local days (Settings → timezone); stored times stay in the
 * application timezone and are compared as instants.
 */
final class SchedulingService
{
    /** Block kinds that count as planned work (breaks and buffers do not). */
    private const WORK_KINDS = ['task', 'focus'];

    public function tz(User $user): string
    {
        return $user->preferredTimezone();
    }

    /** Working window for a local date, or null on a non-working day. @return array{0:CarbonImmutable,1:CarbonImmutable}|null */
    public function window(User $user, CarbonImmutable $day): ?array
    {
        $day = $day->setTimezone($this->tz($user))->startOfDay();
        $days = array_map('intval', (array) $user->preference('work_days'));
        if (!in_array($day->dayOfWeekIso, $days, true)) {
            return null;
        }
        [$sh, $sm] = array_map('intval', explode(':', (string) ($user->preference('work_start') ?: '09:00')) + [0, 0]);
        [$eh, $em] = array_map('intval', explode(':', (string) ($user->preference('work_end') ?: '17:00')) + [0, 0]);
        $start = $day->setTime($sh, $sm);
        $end = $day->setTime($eh, $em);
        return $end->gt($start) ? [$start, $end] : null;
    }

    /**
     * Everything the calendar needs for [from, to] (local dates, inclusive).
     *
     * @return array{timezone:string,items:list<array>,busy:list<array>,days:list<array>,conflicts:list<array>}
     */
    public function range(User $user, string $from, string $to): array
    {
        $tz = $this->tz($user);
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->endOfDay();
        $items = $this->items($user, $start, $end);
        $busy = $this->busy($user, $start, $end);

        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $days[] = $this->dayCapacity($user, $d, $items, $busy);
        }
        return [
            'timezone' => $tz,
            'items' => $items->values()->all(),
            'busy' => $busy->values()->all(),
            'days' => $days,
            'conflicts' => $this->overlaps($items, $busy),
        ];
    }

    /** Blocks plus scheduled tasks that have no block of their own. */
    public function items(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $defaultMinutes = max(5, (int) $user->preference('default_task_minutes'));
        $blocks = ScheduleBlock::query()->ownedBy($user->id)->with('task:id,title,status,priority,recurring_task_id,estimated_minutes')
            ->where('starts_at', '<', LocalDate::db($end))->where('ends_at', '>', LocalDate::db($start))->orderBy('starts_at')->get();
        $blockTaskIds = $blocks->pluck('task_id')->filter()->all();

        $tasks = Task::query()->ownedBy($user->id)->whereNotNull('planned_start')
            ->whereNotIn('status', ['cancelled'])
            ->where('planned_start', '<', LocalDate::db($end))
            ->where(fn ($q) => $q->whereNull('planned_end')->where('planned_start', '>=', LocalDate::db($start->subDay()))->orWhere('planned_end', '>', LocalDate::db($start)))
            ->when($blockTaskIds !== [], fn ($q) => $q->whereNotIn('id', $blockTaskIds))
            ->orderBy('planned_start')->limit(500)
            ->get(['id', 'title', 'status', 'priority', 'planned_start', 'planned_end', 'estimated_minutes', 'recurring_task_id']);

        $out = collect();
        foreach ($blocks as $b) {
            $out->push([
                'type' => 'block', 'id' => $b->id, 'task_id' => $b->task_id, 'kind' => $b->kind ?: 'task',
                'title' => $b->title ?: $b->task?->title, 'status' => $b->task?->status ?? $b->status,
                'priority' => $b->task?->priority, 'recurring' => (bool) $b->task?->recurring_task_id,
                'is_fixed' => (bool) $b->is_fixed, 'starts_at' => $b->starts_at->toIso8601String(), 'ends_at' => $b->ends_at->toIso8601String(),
            ]);
        }
        foreach ($tasks as $t) {
            $endAt = $t->planned_end ?? $t->planned_start->copy()->addMinutes($t->estimated_minutes > 0 ? (int) $t->estimated_minutes : $defaultMinutes);
            if ($endAt->lte($start)) {
                continue;
            }
            $out->push([
                'type' => 'task', 'id' => $t->id, 'task_id' => $t->id, 'kind' => 'task', 'title' => $t->title, 'status' => $t->status,
                'priority' => $t->priority, 'recurring' => (bool) $t->recurring_task_id, 'is_fixed' => false,
                'starts_at' => $t->planned_start->toIso8601String(), 'ends_at' => $endAt->toIso8601String(),
            ]);
        }
        return $out->sortBy('starts_at')->values();
    }

    public function busy(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return CalendarEvent::query()->ownedBy($user->id)->where('is_busy', true)
            ->where('starts_at', '<', LocalDate::db($end))->where('ends_at', '>', LocalDate::db($start))->orderBy('starts_at')->limit(500)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'all_day'])
            ->map(fn (CalendarEvent $e) => [
                'type' => 'event', 'id' => $e->id, 'title' => $e->title, 'all_day' => $e->all_day,
                'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(),
            ]);
    }

    /** Capacity of one local day. */
    public function dayCapacity(User $user, CarbonImmutable $day, ?Collection $items = null, ?Collection $busy = null): array
    {
        $tz = $this->tz($user);
        $day = $day->setTimezone($tz)->startOfDay();
        $dayEnd = $day->endOfDay();
        $items ??= $this->items($user, $day, $dayEnd);
        $busy ??= $this->busy($user, $day, $dayEnd);
        $window = $this->window($user, $day);

        $availableGross = $window ? (int) $window[0]->diffInMinutes($window[1]) : 0;
        $busyMinutes = 0;
        if ($window && $user->preference('calendar_blocks_planning') !== false) {
            $busyMinutes = $this->mergedMinutes($busy->map(fn ($e) => [$e['starts_at'], $e['ends_at']]), $window[0], $window[1]);
        }
        $available = max(0, $availableGross - $busyMinutes);
        $capacity = CapacityPlanner::forUser($user);
        $usable = $capacity->usableMinutes($available);
        $scheduled = $this->mergedMinutes(
            $items->filter(fn ($i) => in_array($i['kind'], self::WORK_KINDS, true) && ($i['status'] ?? null) !== 'cancelled')->map(fn ($i) => [$i['starts_at'], $i['ends_at']]),
            $day, $dayEnd
        );

        $warning = null;
        $loc = $user->preferredLocale();
        if ($scheduled > $available && $scheduled > 0) {
            $warning = ['level' => 'over', 'text' => __('schedule.warn_over', ['scheduled' => self::duration($scheduled, $loc), 'available' => self::duration($available, $loc)], $loc)];
        } elseif ($scheduled > $usable && $scheduled > 0) {
            $warning = ['level' => 'buffer', 'text' => __('schedule.warn_buffer', ['percent' => LocalDate::number((int) round($capacity->bufferRatio() * 100), $loc)], $loc)];
        }

        return [
            'date' => $day->toDateString(),
            'working' => $window !== null,
            'work_start' => $window ? $window[0]->toIso8601String() : null,
            'work_end' => $window ? $window[1]->toIso8601String() : null,
            'available_minutes' => $available,
            'busy_minutes' => $busyMinutes,
            'usable_minutes' => $usable,
            'buffer_minutes' => $available - $usable,
            'scheduled_minutes' => $scheduled,
            'overload_minutes' => max(0, $scheduled - $usable),
            'warning' => $warning,
        ];
    }

    /**
     * Items and busy events overlapping [start, end), excluding the item being moved.
     *
     * @return list<array>
     */
    public function conflictsFor(User $user, CarbonImmutable $start, CarbonImmutable $end, ?string $excludeType = null, ?int $excludeId = null, ?int $excludeTaskId = null): array
    {
        $out = [];
        foreach ($this->items($user, $start->subDay(), $end->addDay()) as $i) {
            if (($excludeType === $i['type'] && $excludeId === $i['id']) || ($excludeTaskId !== null && $i['task_id'] === $excludeTaskId)) {
                continue;
            }
            if (!in_array($i['kind'], self::WORK_KINDS, true) && !$i['is_fixed']) {
                continue; // breaks/buffers are soft
            }
            if (CarbonImmutable::parse($i['starts_at'])->lt($end) && CarbonImmutable::parse($i['ends_at'])->gt($start)) {
                $out[] = $i;
            }
        }
        if ($user->preference('calendar_blocks_planning') !== false) {
            foreach ($this->busy($user, $start, $end) as $e) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Pairs of overlapping work items / busy events (for highlighting in the calendar). */
    private function overlaps(Collection $items, Collection $busy): array
    {
        $all = $items->filter(fn ($i) => in_array($i['kind'], self::WORK_KINDS, true))->values()->concat($busy->values())
            ->map(fn ($x) => $x + ['_s' => CarbonImmutable::parse($x['starts_at'])->getTimestamp(), '_e' => CarbonImmutable::parse($x['ends_at'])->getTimestamp()])
            ->sortBy('_s')->values();
        $pairs = [];
        $n = $all->count();
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n && $all[$b]['_s'] < $all[$a]['_e']; $b++) {
                $pairs[] = [$all[$a]['type'].':'.$all[$a]['id'], $all[$b]['type'].':'.$all[$b]['id']];
            }
        }
        return $pairs;
    }

    /** Minutes covered by the union of intervals, clipped to [from, to]. */
    private function mergedMinutes(Collection $intervals, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $spans = $intervals->map(fn ($p) => [max($from->getTimestamp(), CarbonImmutable::parse($p[0])->getTimestamp()), min($to->getTimestamp(), CarbonImmutable::parse($p[1])->getTimestamp())])
            ->filter(fn ($p) => $p[1] > $p[0])->sortBy(fn ($p) => $p[0])->values();
        $total = 0;
        $curStart = null;
        $curEnd = null;
        foreach ($spans as [$s, $e]) {
            if ($curEnd === null || $s > $curEnd) {
                if ($curEnd !== null) $total += $curEnd - $curStart;
                [$curStart, $curEnd] = [$s, $e];
            } else {
                $curEnd = max($curEnd, $e);
            }
        }
        if ($curEnd !== null) $total += $curEnd - $curStart;
        return intdiv($total, 60);
    }

    /** "7h 30m" / "۷ ساعت و ۳۰ دقیقه" */
    public static function duration(int $minutes, ?string $locale = null): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        $key = $h > 0 && $m > 0 ? 'schedule.dur_hm' : ($h > 0 ? 'schedule.dur_h' : 'schedule.dur_m');
        return __($key, ['h' => LocalDate::number($h, $locale), 'm' => LocalDate::number($m, $locale)], $locale);
    }
}
