<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Domain\Planner\CapacityPlanner;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds a realistic plan proposal for a day or a week. It never changes anything itself —
 * the result is a list of proposed actions that the user reviews and applies explicitly.
 *
 * Inputs: working days/hours, busy calendar events, fixed blocks, existing schedule, planning
 * buffer, deadlines, priorities, importance, goal importance, dependencies (blocked tasks are
 * never scheduled before their prerequisites), and — once there is enough history — the
 * user's measured estimate accuracy (estimates are scaled by it).
 *
 * Kinds: day (today), week (next 7 days), next_week, fix (re-plan this week: re-place flexible
 * work on overloaded days, reschedule missed work, defer what no longer fits).
 */
final class SmartReschedulingService
{
    public const KINDS = ['day', 'week', 'next_week', 'fix'];
    private const MAX_ACTIONS = 40;
    private const SPLIT_CHUNK = 90;

    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly TaskRanker $ranker,
        private readonly PlanInsightsService $insights,
    ) {}

    /** @return array{0:CarbonImmutable,1:int} first local day and number of days */
    public function period(User $user, string $kind): array
    {
        $tz = $user->preferredTimezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        if ($kind === 'day') {
            return [$today, 1];
        }
        if ($kind === 'next_week') {
            $shift = $user->preferredLocale() === 'fa' ? ($today->dayOfWeek + 1) % 7 : ($today->dayOfWeek + 6) % 7; // week starts Sat (fa) / Mon (en)
            return [$today->subDays($shift)->addDays(7), 7];
        }
        return [$today, 7];
    }

    public function propose(User $user, string $kind): array
    {
        $tz = $user->preferredTimezone();
        $now = CarbonImmutable::now();
        [$firstDay, $nDays] = $this->period($user, $kind);
        $periodStart = $firstDay;
        $periodEnd = $firstDay->addDays($nDays - 1)->endOfDay();
        $notBefore = max($now->getTimestamp(), $periodStart->getTimestamp());
        $breakSec = max(0, (int) $user->preference('break_minutes')) * 60;
        $defaultMin = max(5, (int) $user->preference('default_task_minutes'));
        $capacity = CapacityPlanner::forUser($user);

        $items = $this->scheduling->items($user, $periodStart, $periodEnd);
        $busy = $user->preference('calendar_blocks_planning') !== false ? $this->scheduling->busy($user, $periodStart, $periodEnd) : collect();

        // Existing work that "fix" may move: flexible, not done, starting after now, and only on
        // days that are over capacity (days that fit are left alone).
        $movable = [];
        if ($kind === 'fix') {
            $overloaded = [];
            for ($d = 0; $d < $nDays; $d++) {
                $day = $firstDay->addDays($d);
                if ($this->scheduling->dayCapacity($user, $day, $items, $busy)['overload_minutes'] > 0) {
                    $overloaded[$day->toDateString()] = true;
                }
            }
            // Lowest-value items move first; keep the rest in place.
            $scores = [];
            foreach ($items as $i) {
                $startTs = CarbonImmutable::parse($i['starts_at']);
                if (in_array($i['kind'], ['task'], true) && !$i['is_fixed'] && $i['task_id'] && !in_array($i['status'], ['completed', 'cancelled', 'in_progress'], true)
                    && $startTs->getTimestamp() >= $now->getTimestamp() && isset($overloaded[$startTs->setTimezone($tz)->toDateString()])) {
                    $movable[$i['task_id']] = $i + ['minutes' => (int) round((CarbonImmutable::parse($i['ends_at'])->getTimestamp() - $startTs->getTimestamp()) / 60)];
                }
            }
        }

        // Per-day free intervals and work budgets.
        $days = [];
        $overloadBefore = 0;
        $scheduledBefore = 0;
        $usableTotal = 0;
        for ($d = 0; $d < $nDays; $d++) {
            $day = $firstDay->addDays($d);
            $cap = $this->scheduling->dayCapacity($user, $day, $items, $busy);
            $overloadBefore += $cap['overload_minutes'];
            $scheduledBefore += $cap['scheduled_minutes'];
            $window = $this->scheduling->window($user, $day);
            if (!$window) {
                continue;
            }
            $usableTotal += $cap['usable_minutes'];
            $intervals = [[max($window[0]->getTimestamp(), $notBefore), $window[1]->getTimestamp()]];
            $occupiedWork = 0;
            foreach ($busy as $e) {
                $intervals = self::subtract($intervals, CarbonImmutable::parse($e['starts_at'])->getTimestamp(), CarbonImmutable::parse($e['ends_at'])->getTimestamp());
            }
            foreach ($items as $i) {
                if ($i['task_id'] && isset($movable[$i['task_id']]) || ($i['status'] ?? null) === 'cancelled') {
                    continue;
                }
                $s = CarbonImmutable::parse($i['starts_at'])->getTimestamp();
                $e = CarbonImmutable::parse($i['ends_at'])->getTimestamp();
                $intervals = self::subtract($intervals, $s, $e + (in_array($i['kind'], ['task', 'focus'], true) ? $breakSec : 0));
                if (in_array($i['kind'], ['task', 'focus'], true) && $s < $window[1]->getTimestamp() && $e > $window[0]->getTimestamp()) {
                    $occupiedWork += (int) round((min($e, $window[1]->getTimestamp()) - max($s, $window[0]->getTimestamp())) / 60);
                }
            }
            $days[] = [
                'date' => $day->toDateString(), 'day' => $day, 'end' => $window[1],
                'intervals' => array_values(array_filter($intervals, fn ($iv) => $iv[1] - $iv[0] >= 300)),
                'budget' => max(0, $capacity->usableMinutes((int) $cap['available_minutes']) - $occupiedWork),
            ];
        }

        // Candidates: unblocked open work not already scheduled in this period (plus movable items).
        $factor = $this->insights->estimateFactor($user);
        $scheduledIds = collect($items)->pluck('task_id')->filter()->all();
        $candidates = [];
        foreach ($this->ranker->candidates($user) as $t) {
            if ($t->status === 'waiting') continue;
            $isMovable = isset($movable[$t->id]);
            $plannedInPast = $t->planned_start && CarbonImmutable::instance($t->planned_end ?? $t->planned_start)->lt($now);
            $alreadyPlanned = in_array($t->id, $scheduledIds, true) && !$isMovable && !$plannedInPast;
            $plannedLater = $t->planned_start && CarbonImmutable::instance($t->planned_start)->gt($periodEnd);
            if ($alreadyPlanned || $plannedLater) continue;
            if ($t->blocked_by !== []) continue;
            // A recurring occurrence is never planned before its own date.
            $earliest = null;
            if ($t->recurring_task_id) {
                $earliest = $t->occurrence_date ? CarbonImmutable::parse($t->occurrence_date->toDateString(), $tz)->getTimestamp() : null;
                if ($earliest !== null && $earliest > $periodEnd->getTimestamp()) continue;
            }
            $s = $this->ranker->score($t, $now, $tz);
            $minutes = $isMovable ? max(5, (int) $movable[$t->id]['minutes']) : (int) ($t->estimated_minutes > 0 ? $t->estimated_minutes : $defaultMin);
            $reasons = $s['reasons'];
            if ($factor !== 1.0 && !$isMovable) {
                $adjusted = (int) (ceil($minutes * $factor / 5) * 5);
                if ($adjusted !== $minutes) {
                    $reasons[] = ['code' => 'history_adjusted', 'params' => ['from' => $minutes, 'to' => $adjusted], 'points' => 0];
                    $minutes = $adjusted;
                }
            }
            if ($plannedInPast && !$isMovable) {
                $reasons[] = ['code' => 'missed_slot', 'params' => ['date' => $t->planned_start->toIso8601String()], 'points' => 0];
            }
            $candidates[] = ['task' => $t, 'score' => $s['score'] + ($plannedInPast ? 15 : 0), 'minutes' => $minutes, 'reasons' => $reasons, 'movable' => $movable[$t->id] ?? null, 'earliest' => $earliest];
        }
        usort($candidates, fn ($a, $b) => [$b['score'], $a['task']->deadline?->getTimestamp() ?? PHP_INT_MAX] <=> [$a['score'], $b['task']->deadline?->getTimestamp() ?? PHP_INT_MAX]);

        // Low-value, no-deadline work is only planned into genuinely free capacity.
        $actions = [];
        $placedMinutes = 0;
        $n = 0;
        foreach ($candidates as $c) {
            if (count($actions) >= self::MAX_ACTIONS) break;
            $t = $c['task'];
            $deadlineTs = $t->deadline ? CarbonImmutable::instance($t->deadline)->getTimestamp() : null;
            $slot = $this->findSlot($days, $c['minutes'], $deadlineTs, $breakSec, $c['earliest']);
            if ($slot === null && $deadlineTs !== null && $c['earliest'] === null) {
                $slot = $this->findSlot($days, $c['minutes'], null, $breakSec); // past the deadline is still better than nothing
                if ($slot) $c['reasons'][] = ['code' => 'after_deadline', 'params' => [], 'points' => 0];
            }
            if ($slot !== null) {
                [$di, $start, $end] = $slot;
                $to = ['starts_at' => CarbonImmutable::createFromTimestamp($start)->toIso8601String(), 'ends_at' => CarbonImmutable::createFromTimestamp($end)->toIso8601String()];
                $from = $c['movable'] ? ['starts_at' => $c['movable']['starts_at'], 'ends_at' => $c['movable']['ends_at']] : null;
                if ($from && CarbonImmutable::parse($from['starts_at'])->getTimestamp() === $start) {
                    continue; // already in the right place
                }
                $placedMinutes += $c['minutes'];
                $actions[] = $this->action(++$n, $from ? 'move' : 'schedule', $t, $c, $from, $to);
                continue;
            }
            // Doesn't fit anywhere as one block.
            if ($c['minutes'] > 120 && $this->freeMinutes($days) >= $c['minutes']) {
                $actions[] = $this->action(++$n, 'split', $t, $c, null, null) + ['parts' => (int) ceil($c['minutes'] / self::SPLIT_CHUNK)];
            } elseif ($deadlineTs !== null && $deadlineTs <= $periodEnd->getTimestamp()) {
                $c['reasons'][] = ['code' => 'no_capacity_before_deadline', 'params' => [], 'points' => 0];
                $actions[] = $this->action(++$n, 'at_risk', $t, $c, null, null);
            } elseif ($c['movable']) {
                $c['reasons'][] = ['code' => 'no_capacity', 'params' => [], 'points' => 0];
                $actions[] = $this->action(++$n, 'defer', $t, $c, ['starts_at' => $c['movable']['starts_at'], 'ends_at' => $c['movable']['ends_at']], null);
            }
            // Unscheduled low-priority work that doesn't fit is simply left in the backlog.
        }

        // Overloaded period: suggest protecting a recovery buffer on the last working day.
        if ($overloadBefore > 0 && $days !== []) {
            $last = end($days);
            foreach (array_reverse($last['intervals']) as $iv) {
                if ($iv[1] - $iv[0] >= 3600) {
                    $actions[] = ['key' => 'a'.(++$n), 'type' => 'add_buffer', 'task_id' => null, 'title' => null, 'from' => null,
                        'to' => ['starts_at' => CarbonImmutable::createFromTimestamp($iv[1] - 3600)->toIso8601String(), 'ends_at' => CarbonImmutable::createFromTimestamp($iv[1])->toIso8601String()],
                        'minutes' => 60, 'reasons' => [['code' => 'recovery_buffer', 'params' => [], 'points' => 0]], 'selected' => true];
                    break;
                }
            }
        }

        return [
            'kind' => $kind,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'metrics' => [
                'working_days' => count($days),
                'usable_minutes' => $usableTotal,
                'scheduled_minutes_before' => $scheduledBefore,
                'overload_minutes_before' => $overloadBefore,
                'proposed_minutes' => $placedMinutes,
                'candidates' => count($candidates),
                'estimate_factor' => $factor,
                'history_used' => $factor !== 1.0,
            ],
            'headline' => $overloadBefore > 0 ? ['key' => 'overloaded', 'params' => ['duration' => $overloadBefore]]
                : ($actions === [] ? ['key' => 'nothing_to_do', 'params' => []] : ['key' => 'fits', 'params' => ['count' => count($actions)]]),
            'actions' => $actions,
        ];
    }

    private function action(int $n, string $type, Task $t, array $c, ?array $from, ?array $to): array
    {
        return [
            'key' => 'a'.$n, 'type' => $type, 'task_id' => $t->id, 'title' => $t->title, 'from' => $from, 'to' => $to,
            'minutes' => $c['minutes'], 'reasons' => array_map(fn ($r) => ['code' => $r['code'], 'params' => $r['params']], $c['reasons']),
            'selected' => !in_array($type, ['at_risk'], true),
        ];
    }

    /** Earliest slot fitting $minutes (within day budget, before the deadline when given). Consumes it. */
    private function findSlot(array &$days, int $minutes, ?int $deadlineTs, int $breakSec, ?int $earliestTs = null): ?array
    {
        $need = $minutes * 60;
        foreach ($days as $di => &$d) {
            if ($d['budget'] < $minutes) continue;
            foreach ($d['intervals'] as $ii => [$s0, $e]) {
                $s = $earliestTs !== null ? max($s0, $earliestTs) : $s0;
                $s = (int) (ceil($s / 300) * 300); // start on a 5-minute mark
                if ($e - $s < $need) continue;
                if ($deadlineTs !== null && $s + $need > $deadlineTs) return null;
                $d['intervals'][$ii] = [$s + $need + $breakSec, $e];
                if ($s > $s0) {
                    $d['intervals'][] = [$s0, $s];
                    usort($d['intervals'], fn ($a, $b) => $a[0] <=> $b[0]);
                }
                $d['intervals'] = array_values(array_filter($d['intervals'], fn ($iv) => $iv[1] - $iv[0] >= 300));
                $d['budget'] -= $minutes;
                return [$di, $s, $s + $need];
            }
        }
        return null;
    }

    private function freeMinutes(array $days): int
    {
        $sum = 0;
        foreach ($days as $d) {
            $sum += min($d['budget'], (int) (array_sum(array_map(fn ($iv) => $iv[1] - $iv[0], $d['intervals'])) / 60));
        }
        return $sum;
    }

    /** @param list<array{0:int,1:int}> $intervals */
    private static function subtract(array $intervals, int $s, int $e): array
    {
        $out = [];
        foreach ($intervals as [$a, $b]) {
            if ($e <= $a || $s >= $b) { $out[] = [$a, $b]; continue; }
            if ($s > $a) $out[] = [$a, $s];
            if ($e < $b) $out[] = [$e, $b];
        }
        return $out;
    }
}
