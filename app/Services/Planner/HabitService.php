<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Habit;
use App\Models\HabitEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Habit scheduling, check-ins, streaks and reports — all in the user's local calendar days.
 *
 * Encouraging by design:
 *  - today is never "missed" while it is still today (it is "pending");
 *  - paused days and days the habit is not scheduled never break a streak;
 *  - weekly habits ("N times a week") are judged per week, and the current week only counts
 *    once it is met — an unfinished week never breaks the streak.
 *
 * Success of one day:
 *  - binary, build:   completed
 *  - binary, reduce:  completed ("I kept it today")
 *  - numeric, build:  value ≥ target (any value > 0 without a target)
 *  - numeric, reduce: value ≤ target (0 without a target) — only when the day was logged
 */
final class HabitService
{
    /**
     * Days are handled as plain calendar dates (midnight UTC objects), so comparisons never mix
     * timezones. "Today" is the user's local date.
     */
    public function today(User $user): CarbonImmutable
    {
        return self::day(CarbonImmutable::now($user->preferredTimezone())->toDateString());
    }

    public static function day(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), 'UTC');
    }

    /** First day of the week for this user: Saturday for Persian, Monday otherwise. */
    public function weekStart(User $user, CarbonImmutable $day): CarbonImmutable
    {
        $first = $user->preferredLocale() === 'fa' ? CarbonImmutable::SATURDAY : CarbonImmutable::MONDAY;
        return $day->startOfWeek($first);
    }

    // ------------------------------------------------------------------ rules

    public function isPausedOn(Habit $h, string $date): bool
    {
        foreach ((array) $h->pauses as $p) {
            $from = $p['from'] ?? null;
            $to = $p['to'] ?? null;
            if ($from !== null && $date >= $from && ($to === null || $date < $to)) return true;
        }
        return false;
    }

    /** Is the habit scheduled on this local date (ignoring pauses)? */
    public function scheduledOn(Habit $h, CarbonImmutable $d): bool
    {
        $start = self::day($h->start_date->toDateString());
        if ($d->lt($start)) return false;
        return match ($h->frequency) {
            'weekdays' => in_array($d->isoWeekday(), array_map('intval', (array) $h->weekdays), true),
            'interval' => (int) $start->diffInDays($d) % max(1, (int) $h->interval_days) === 0,
            default => true, // daily, weekly (any day can count towards the week)
        };
    }

    public function isDue(Habit $h, CarbonImmutable $d): bool
    {
        return $this->scheduledOn($h, $d) && !$this->isPausedOn($h, $d->toDateString());
    }

    public function isSuccess(Habit $h, ?HabitEntry $e): bool
    {
        if (!$e) return false;
        if ($h->measure === 'binary') return (bool) $e->completed;
        if ($e->value === null) return false;
        $target = $h->target_value;
        return $h->kind === 'reduce'
            ? $e->value <= ($target ?? 0)
            : ($target !== null ? $e->value >= $target : $e->value > 0);
    }

    /** done | partial | pending | missed | not_due | paused | future | before_start */
    public function dayState(Habit $h, CarbonImmutable $d, ?HabitEntry $e, CarbonImmutable $today): string
    {
        if ($d->gt($today)) return 'future';
        if ($d->lt(self::day($h->start_date->toDateString()))) return 'before_start';
        if ($this->isPausedOn($h, $d->toDateString())) return 'paused';
        if ($this->isSuccess($h, $e)) return 'done';
        if (!$this->scheduledOn($h, $d)) return 'not_due';
        if ($h->frequency === 'weekly') return $e ? 'partial' : ($d->eq($today) ? 'pending' : 'not_due');
        if ($e && $h->measure === 'numeric' && $h->kind === 'build' && $e->value > 0) return $d->eq($today) ? 'pending' : 'partial';
        return $d->eq($today) ? 'pending' : 'missed';
    }

    // ------------------------------------------------------------------ data

    /** @return Collection<string,HabitEntry> keyed by Y-m-d */
    private function entries(Habit $h, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return HabitEntry::withoutGlobalScopes()->where('habit_id', $h->id)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn (HabitEntry $e) => $e->entry_date->toDateString());
    }

    /** Streaks and consistency for one habit. */
    public function stats(Habit $h, User $user): array
    {
        $today = $this->today($user);
        $start = self::day($h->start_date->toDateString());
        $from = $start->max($today->subDays(730)); // two years is plenty for streak history
        $entries = $this->entries($h, $from, $today);

        if ($h->frequency === 'weekly') {
            [$current, $longest] = $this->weeklyStreaks($h, $user, $from, $today, $entries);
            $unit = 'weeks';
        } else {
            [$current, $longest] = $this->dailyStreaks($h, $from, $today, $entries);
            $unit = 'days';
        }

        // Consistency over the last 30 days and the current week (pending today excluded).
        $rate = function (CarbonImmutable $a) use ($h, $today, $entries, $user) {
            if ($h->frequency === 'weekly') {
                $weeks = 0; $ok = 0.0;
                for ($w = $this->weekStart($user, $a); $w->lte($today); $w = $w->addWeek()) {
                    $done = 0; $active = false;
                    for ($d = $w; $d->lt($w->addWeek()) && $d->lte($today); $d = $d->addDay()) {
                        if ($d->lt($a) || $this->isPausedOn($h, $d->toDateString()) || !$this->scheduledOn($h, $d)) continue;
                        $active = true;
                        if ($this->isSuccess($h, $entries->get($d->toDateString()))) $done++;
                    }
                    if (!$active) continue;
                    $weeks++;
                    $ok += min(1, $done / max(1, (int) $h->times_per_week));
                }
                return $weeks ? (int) round($ok / $weeks * 100) : null;
            }
            $due = 0; $done = 0;
            for ($d = $a; $d->lte($today); $d = $d->addDay()) {
                if (!$this->isDue($h, $d)) continue;
                $ok = $this->isSuccess($h, $entries->get($d->toDateString()));
                if ($d->eq($today) && !$ok) continue; // today is not over yet
                $due++;
                if ($ok) $done++;
            }
            return $due ? (int) round($done / $due * 100) : null;
        };
        $weekFrom = $this->weekStart($user, $today)->max($start);
        $monthFrom = $today->subDays(29)->max($start);

        $todayEntry = $entries->get($today->toDateString());
        $recent = [];
        for ($d = $today->subDays(6); $d->lte($today); $d = $d->addDay()) {
            $recent[] = ['date' => $d->toDateString(), 'state' => $this->dayState($h, $d, $entries->get($d->toDateString()), $today)];
        }
        return [
            'recent' => $recent,
            'current_streak' => $current,
            'longest_streak' => $longest,
            'streak_unit' => $unit,
            'week_rate' => $rate($weekFrom),
            'month_rate' => $rate($monthFrom),
            'due_today' => $this->isDue($h, $today),
            'done_today' => $this->isSuccess($h, $todayEntry),
            'today_entry' => $todayEntry,
            'week_count' => $h->frequency === 'weekly' ? $entries->filter(fn ($e, $k) => $k >= $this->weekStart($user, $today)->toDateString() && $this->isSuccess($h, $e))->count() : null,
        ];
    }

    private function dailyStreaks(Habit $h, CarbonImmutable $from, CarbonImmutable $today, Collection $entries): array
    {
        $longest = 0; $run = 0;
        for ($d = $from; $d->lte($today); $d = $d->addDay()) {
            if (!$this->isDue($h, $d)) continue;
            if ($this->isSuccess($h, $entries->get($d->toDateString()))) {
                $longest = max($longest, ++$run);
            } elseif (!$d->eq($today)) {
                $run = 0;
            }
        }
        return [$run, $longest]; // the run still open at the end is the current streak
    }

    private function weeklyStreaks(Habit $h, User $user, CarbonImmutable $from, CarbonImmutable $today, Collection $entries): array
    {
        $longest = 0; $run = 0;
        $thisWeek = $this->weekStart($user, $today);
        for ($w = $this->weekStart($user, $from); $w->lte($today); $w = $w->addWeek()) {
            $done = 0; $active = false;
            for ($d = $w; $d->lt($w->addWeek()) && $d->lte($today); $d = $d->addDay()) {
                if ($d->lt($from) || !$this->isDue($h, $d)) continue;
                $active = true;
                if ($this->isSuccess($h, $entries->get($d->toDateString()))) $done++;
            }
            if (!$active) continue; // fully paused week
            if ($done >= max(1, (int) $h->times_per_week)) {
                $longest = max($longest, ++$run);
            } elseif (!$w->eq($thisWeek)) {
                $run = 0;
            }
        }
        return [$run, $longest];
    }

    /**
     * Day states for the last N weeks (aligned to the user's week, through the end of this week)
     * for the heatmap, plus a missed-weekday analysis. Rolling weeks instead of calendar months
     * work the same for Jalali and Gregorian users.
     */
    public function history(Habit $h, User $user, int $weeks = 12): array
    {
        $today = $this->today($user);
        $first = $this->weekStart($user, $today)->subWeeks(max(1, min(53, $weeks)) - 1);
        $last = $this->weekStart($user, $today)->addDays(6);
        $entries = $this->entries($h, $first, $last);
        $days = [];
        for ($d = $first; $d->lte($last); $d = $d->addDay()) {
            $e = $entries->get($d->toDateString());
            $days[] = ['date' => $d->toDateString(), 'state' => $this->dayState($h, $d, $e, $today),
                'value' => $e?->value, 'note' => $e?->note];
        }

        // Which weekdays get missed most (last 8 weeks) — only days that were actually due.
        $since = $today->subDays(56)->max(self::day($h->start_date->toDateString()));
        $recent = $this->entries($h, $since, $today);
        $by = [];
        for ($d = $since; $d->lt($today); $d = $d->addDay()) {
            if ($h->frequency === 'weekly' || !$this->isDue($h, $d)) continue;
            $k = $d->isoWeekday();
            $by[$k] ??= ['weekday' => $k, 'due' => 0, 'missed' => 0];
            $by[$k]['due']++;
            if (!$this->isSuccess($h, $recent->get($d->toDateString()))) $by[$k]['missed']++;
        }
        $missed = array_values(array_filter($by, fn ($r) => $r['due'] >= 2 && $r['missed'] > 0));
        usort($missed, fn ($a, $b) => ($b['missed'] / $b['due']) <=> ($a['missed'] / $a['due']));

        return ['from' => $first->toDateString(), 'to' => $last->toDateString(), 'days' => $days, 'missed_weekdays' => array_slice($missed, 0, 3),
            'entries' => $entries->values()->filter(fn ($e) => $e->note !== null && $e->note !== '')->values()];
    }

    /**
     * Overview for the dashboard: today's habits, week consistency and a 12-week heatmap where
     * each day is the share of due habits that were done.
     */
    public function overview(User $user, int $weeks = 12): array
    {
        $today = $this->today($user);
        $habits = Habit::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->get();
        $from = $this->weekStart($user, $today)->subWeeks($weeks - 1);
        $all = HabitEntry::withoutGlobalScopes()->whereIn('habit_id', $habits->pluck('id'))
            ->whereBetween('entry_date', [$from->toDateString(), $today->toDateString()])->get()
            ->groupBy('habit_id')->map(fn ($g) => $g->keyBy(fn ($e) => $e->entry_date->toDateString()));

        $heat = [];
        for ($d = $from; $d->lte($today); $d = $d->addDay()) {
            $due = 0; $done = 0;
            foreach ($habits as $h) {
                // "N times a week" habits have no single due day, so they are left out of the daily map.
                if ($h->frequency === 'weekly' || !$this->isDue($h, $d)) continue;
                $due++;
                if ($this->isSuccess($h, $all->get($h->id)?->get($d->toDateString()))) $done++;
            }
            $heat[] = ['date' => $d->toDateString(), 'due' => $due, 'done' => $done, 'rate' => $due ? (int) round($done / $due * 100) : null];
        }

        $items = $habits->where('status', 'active')->map(fn (Habit $h) => $this->present($h, $user))->values();
        $dueToday = $items->where('due_today', true);
        $weekStart = $this->weekStart($user, $today)->toDateString();
        $wDue = 0; $wDone = 0;
        foreach ($heat as $r) {
            if ($r['date'] < $weekStart) continue;
            // Today is not over: only what is already done today counts (never a "miss").
            $wDue += $r['date'] === $today->toDateString() ? $r['done'] : $r['due'];
            $wDone += $r['done'];
        }

        return [
            'today' => ['due' => $dueToday->count(), 'done' => $dueToday->where('done_today', true)->count()],
            'week_rate' => $wDue ? (int) round($wDone / $wDue * 100) : null,
            'habits' => $items,
            'heatmap' => $heat,
            'paused' => $habits->where('status', 'paused')->count(),
        ];
    }

    public function present(Habit $h, User $user): array
    {
        return array_merge($h->toArray(), $this->stats($h, $user));
    }

    // ------------------------------------------------------------------ mutations

    /**
     * Record a day. Binary: completed (default: toggle). Numeric: value (or add, e.g. +1 glass).
     * A binary day that is unchecked with no note is removed, so "no entry" means "not done".
     */
    public function check(Habit $h, User $user, array $data): ?HabitEntry
    {
        $today = $this->today($user);
        $date = isset($data['date']) ? self::day($data['date']) : $today;
        if ($date->gt($today)) {
            throw ValidationException::withMessages(['date' => __('habits.no_future')]);
        }
        if ($date->lt(self::day($h->start_date->toDateString()))) {
            throw ValidationException::withMessages(['date' => __('habits.before_start')]);
        }
        $entry = HabitEntry::withoutGlobalScopes()->firstOrNew(['habit_id' => $h->id, 'entry_date' => $date->toDateString()], ['user_id' => $h->user_id]);

        if ($h->measure === 'binary') {
            $entry->completed = array_key_exists('completed', $data) && $data['completed'] !== null ? (bool) $data['completed'] : !$entry->completed;
        } else {
            $value = isset($data['add']) ? (float) ($entry->value ?? 0) + (float) $data['add'] : ($data['value'] ?? $entry->value);
            $entry->value = $value === null ? null : max(0, round((float) $value, 2));
            $entry->completed = $this->isSuccess($h, $entry);
        }
        if (array_key_exists('note', $data)) $entry->note = $data['note'] !== null ? mb_substr((string) $data['note'], 0, 1000) : null;

        if ($h->measure === 'binary' && !$entry->completed && ($entry->note === null || $entry->note === '')
            || $h->measure === 'numeric' && $entry->value === null && ($entry->note === null || $entry->note === '')) {
            if ($entry->exists) $entry->delete();
            return null;
        }
        $entry->save();
        return $entry;
    }

    public function pause(Habit $h, User $user): Habit
    {
        if ($h->status === 'active') {
            $pauses = (array) $h->pauses;
            $pauses[] = ['from' => $this->today($user)->addDay()->toDateString(), 'to' => null]; // today still counts if done
            $h->update(['status' => 'paused', 'pauses' => $pauses]);
        }
        return $h->refresh();
    }

    public function resume(Habit $h, User $user): Habit
    {
        if ($h->status === 'paused') {
            $pauses = (array) $h->pauses;
            $today = $this->today($user)->toDateString();
            foreach ($pauses as $i => $p) {
                if (($p['to'] ?? null) === null) {
                    // Resumed before the pause even started (same day): drop it entirely.
                    if (($p['from'] ?? $today) > $today) unset($pauses[$i]);
                    else $pauses[$i]['to'] = $today;
                }
            }
            $h->update(['status' => 'active', 'pauses' => array_values($pauses)]);
        }
        return $h->refresh();
    }
}
