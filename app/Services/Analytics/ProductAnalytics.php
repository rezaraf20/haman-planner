<?php
declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * SaaS metrics computed only from recorded data. When a metric cannot be computed reliably
 * (no data yet, empty cohort, tracking started too recently) it is returned as null with a
 * reason — never estimated. Money is reported per currency; there is no FX conversion.
 *
 * Definitions (also shown in the admin UI):
 *  - DAU/WAU/MAU: distinct users with any authenticated web/API/Telegram activity on the day /
 *    last 7 / last 30 days (user_activity_days; tracked since the first recorded day).
 *  - Activation: share of users who signed up 7–37 days ago and created a task within 7 days.
 *  - Trial conversion: trials that ended in the last 90 days followed by a paid payment.
 *  - Paid conversion: share of users who signed up in the last 90 days with ≥1 paid payment.
 *  - Churn (30 days): paid subscribers 30 days ago who no longer have a paid subscription.
 *  - Retention W1 / M1: users active on days 7–13 / 28–34 after sign-up (cohorts after tracking began).
 *  - MRR: last paid amount of each active or past-due paid subscription, yearly ÷ 12.
 *  - ARPU: MRR ÷ number of those subscriptions, per currency.
 */
final class ProductAnalytics
{
    public function all(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        return [
            'active' => $this->activeUsers($now),
            'activation' => $this->activation($now),
            'trial_conversion' => $this->trialConversion($now),
            'paid_conversion' => $this->paidConversion($now),
            'churn' => $this->churn($now),
            'retention_w1' => $this->retention($now, 7, 13),
            'retention_m1' => $this->retention($now, 28, 34),
            'revenue' => $this->revenue(),
        ];
    }

    private static function rate(int $n, int $of, int $minCohort = 1): array
    {
        return ['n' => $n, 'of' => $of, 'rate' => $of >= $minCohort && $of > 0 ? round($n / $of * 100, 1) : null];
    }

    public function trackedSince(): ?CarbonImmutable
    {
        $d = DB::table('user_activity_days')->min('day');
        return $d ? CarbonImmutable::parse((string) $d)->startOfDay() : null;
    }

    public function activeUsers(CarbonImmutable $now): array
    {
        $since = $this->trackedSince();
        $today = $now->toDateString();
        $count = fn (int $days) => (int) DB::table('user_activity_days')->where('day', '>', $now->subDays($days)->toDateString())->where('day', '<=', $today)->distinct()->count('user_id');
        $dau = $since ? $count(1) : null;
        $wau = $since && $since->lte($now->subDays(6)->startOfDay()) ? $count(7) : null;
        $mau = $since && $since->lte($now->subDays(29)->startOfDay()) ? $count(30) : null;
        $series = [];
        if ($since) {
            $from = $now->subDays(13)->toDateString();
            $raw = DB::table('user_activity_days')->where('day', '>=', $from)->selectRaw('day, count(*) as c')->groupBy('day')->pluck('c', 'day')
                ->mapWithKeys(fn ($c, $d) => [substr((string) $d, 0, 10) => (int) $c])->all();
            for ($i = 13; $i >= 0; $i--) {
                $d = $now->subDays($i)->toDateString();
                $series[$d] = $since->toDateString() <= $d ? ($raw[$d] ?? 0) : null;
            }
        }
        return [
            'tracked_since' => $since?->toDateString(),
            'dau' => $dau, 'wau' => $wau, 'mau' => $mau,
            'wau_partial' => $since !== null && $wau === null ? $count(7) : null,
            'mau_partial' => $since !== null && $mau === null ? $count(30) : null,
            'stickiness' => $dau !== null && $mau ? round($dau / $mau * 100, 1) : null,
            'series' => $series,
        ];
    }

    public function activation(CarbonImmutable $now): array
    {
        $cohort = User::query()->where('is_admin', false)
            ->whereBetween('created_at', [$now->subDays(37), $now->subDays(7)])->pluck('created_at', 'id');
        if ($cohort->isEmpty()) {
            return self::rate(0, 0);
        }
        $firstTask = DB::table('tasks')->whereIn('user_id', $cohort->keys())->selectRaw('user_id, min(created_at) as first')->groupBy('user_id')->pluck('first', 'user_id');
        $activated = $cohort->filter(fn ($created, $id) => isset($firstTask[$id]) && CarbonImmutable::parse((string) $firstTask[$id])->lte(CarbonImmutable::parse((string) $created)->addDays(7)))->count();
        return self::rate($activated, $cohort->count());
    }

    public function trialConversion(CarbonImmutable $now): array
    {
        $trials = Subscription::query()->whereNotNull('trial_ends_at')->whereBetween('trial_ends_at', [$now->subDays(90), $now])->get(['user_id', 'created_at']);
        if ($trials->isEmpty()) {
            return self::rate(0, 0);
        }
        $converted = $trials->filter(fn ($t) => Payment::query()->where('user_id', $t->user_id)->where('status', 'paid')->where('paid_at', '>=', $t->created_at)->exists())->count();
        return self::rate($converted, $trials->count());
    }

    public function paidConversion(CarbonImmutable $now): array
    {
        $ids = User::query()->where('is_admin', false)->where('created_at', '>=', $now->subDays(90))->pluck('id');
        $paying = $ids->isEmpty() ? 0 : Payment::query()->whereIn('user_id', $ids)->where('status', 'paid')->distinct()->count('user_id');
        return self::rate($paying, $ids->count());
    }

    /** Paid subscription = has a billing interval (not a trial or a manual grant). */
    public function churn(CarbonImmutable $now): array
    {
        $start = $now->subDays(30);
        $atStart = Subscription::query()->whereNotNull('billing_interval')->where('status', '!=', 'trialing')
            ->where('current_period_start', '<=', $start)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>', $start))
            ->distinct()->pluck('user_id');
        if ($atStart->isEmpty()) {
            return self::rate(0, 0);
        }
        $stillPaying = Subscription::query()->current()->whereIn('user_id', $atStart)->whereNotNull('billing_interval')->where('status', '!=', 'trialing')->distinct()->pluck('user_id');
        return self::rate($atStart->diff($stillPaying)->count(), $atStart->count());
    }

    public function retention(CarbonImmutable $now, int $fromDay, int $toDay): array
    {
        $since = $this->trackedSince();
        if ($since === null) {
            return self::rate(0, 0) + ['reason' => 'no_tracking'];
        }
        $cohort = User::query()->where('is_admin', false)->where('created_at', '>=', $since)
            ->where('created_at', '<=', $now->subDays($toDay + 1))->where('created_at', '>=', $now->subDays(90))->pluck('created_at', 'id');
        if ($cohort->isEmpty()) {
            return self::rate(0, 0) + ['reason' => 'cohort_too_young'];
        }
        $days = DB::table('user_activity_days')->whereIn('user_id', $cohort->keys())->get(['user_id', 'day'])->groupBy('user_id');
        $retained = $cohort->filter(function ($created, $id) use ($days, $fromDay, $toDay) {
            $c = CarbonImmutable::parse((string) $created)->startOfDay();
            [$a, $b] = [$c->addDays($fromDay)->toDateString(), $c->addDays($toDay)->toDateString()];
            return collect($days[$id] ?? [])->contains(fn ($r) => ($d = substr((string) $r->day, 0, 10)) >= $a && $d <= $b);
        })->count();
        return self::rate($retained, $cohort->count());
    }

    /** @return array<string,array{mrr:int,subscriptions:int,arpu:?int}> per currency (minor units as stored) */
    public function revenue(): array
    {
        $subs = Subscription::query()->current()->whereIn('status', ['active', 'past_due'])->whereNotNull('billing_interval')->get(['id', 'billing_interval']);
        $out = [];
        foreach ($subs as $s) {
            $p = Payment::query()->where('subscription_id', $s->id)->where('status', 'paid')->latest('paid_at')->first(['amount', 'currency']);
            if (!$p) {
                continue; // e.g. imported/legacy rows without a recorded payment: not counted rather than guessed
            }
            $monthly = $s->billing_interval === 'yearly' ? intdiv((int) $p->amount, 12) : (int) $p->amount;
            $out[$p->currency] ??= ['mrr' => 0, 'subscriptions' => 0, 'arpu' => null];
            $out[$p->currency]['mrr'] += $monthly;
            $out[$p->currency]['subscriptions']++;
        }
        foreach ($out as $cur => $r) {
            $out[$cur]['arpu'] = $r['subscriptions'] > 0 ? intdiv($r['mrr'], $r['subscriptions']) : null;
        }
        return $out;
    }
}
