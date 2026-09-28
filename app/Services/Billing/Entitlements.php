<?php
declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\PlanLimitReached;
use App\Models\Goal;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single place that answers "may this user do X?".
 *
 *   canUse($user, 'telegram')           feature flag from the user's plan
 *   limit($user, 'ai_requests')         null = unlimited
 *   remaining($user, 'ai_requests')     null = unlimited
 *   consume($user, 'ai_requests')       atomic monthly metering; throws PlanLimitReached
 *   ensureCanCreate($user, 'open_tasks') count-based limits; throws PlanLimitReached
 *
 * Admins are never limited. If no plan exists at all (fresh install, not seeded yet)
 * everything is allowed, so existing behaviour is preserved.
 */
final class Entitlements
{
    /** @var array<int,?Plan> per-request cache */
    private array $planCache = [];

    private static bool $tablesReady = false;

    /**
     * During a deploy, new code can briefly run before `migrate` has created the billing
     * tables (e.g. worker/scheduler containers). Treat that as "no plans" instead of failing.
     */
    private static function tablesReady(): bool
    {
        if (!self::$tablesReady) {
            try {
                self::$tablesReady = Schema::hasTable('plans') && Schema::hasTable('subscriptions') && Schema::hasTable('usage_counters');
            } catch (\Throwable) {
                return false;
            }
        }
        return self::$tablesReady;
    }

    public function currentSubscription(User $user): ?Subscription
    {
        if (!self::tablesReady()) {
            return null;
        }
        return Subscription::query()->with('plan')->where('user_id', $user->id)->current()
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'trialing' THEN 1 ELSE 2 END")
            ->latest('current_period_end')->first();
    }

    public function plan(User $user): ?Plan
    {
        if (array_key_exists($user->id, $this->planCache)) {
            return $this->planCache[$user->id];
        }
        if (!self::tablesReady()) {
            return $this->planCache[$user->id] = null;
        }
        $plan = $this->currentSubscription($user)?->plan
            ?? Plan::query()->where('is_default', true)->where('is_active', true)->orderBy('sort_order')->first();
        return $this->planCache[$user->id] = $plan;
    }

    public function forget(User $user): void
    {
        unset($this->planCache[$user->id]);
    }

    private function unrestricted(User $user): bool
    {
        return $user->is_admin === true || $this->plan($user) === null;
    }

    public function canUse(User $user, string $feature): bool
    {
        return $this->unrestricted($user) || $this->plan($user)->hasFeature($feature);
    }

    public function ensureFeature(User $user, string $feature): void
    {
        if (!$this->canUse($user, $feature)) {
            throw new PlanLimitReached('feature_'.$feature);
        }
    }

    public function limit(User $user, string $metric): ?int
    {
        return $this->unrestricted($user) ? null : $this->plan($user)->limit($metric);
    }

    public static function period(): string
    {
        return now()->format('Y-m');
    }

    public function used(User $user, string $metric): int
    {
        if (!self::tablesReady() && config("billing.metrics.$metric.type") === 'monthly') {
            return 0;
        }
        return match (config("billing.metrics.$metric.type")) {
            'monthly' => (int) UsageCounter::query()->where(['user_id' => $user->id, 'metric' => $metric, 'period' => self::period()])->value('used'),
            'count' => $this->count($user, $metric),
            'storage' => $this->storageMb($user),
            default => 0,
        };
    }

    public function remaining(User $user, string $metric): ?int
    {
        $limit = $this->limit($user, $metric);
        return $limit === null ? null : max(0, $limit - $this->used($user, $metric));
    }

    /** Atomically meter one unit; throws when the monthly allowance is exhausted. */
    public function consume(User $user, string $metric, int $amount = 1): void
    {
        if (!self::tablesReady()) {
            return;
        }
        $limit = $this->limit($user, $metric);
        $where = ['user_id' => $user->id, 'metric' => $metric, 'period' => self::period()];
        UsageCounter::query()->insertOrIgnore($where + ['used' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $q = DB::table('usage_counters')->where($where);
        if ($limit !== null) {
            $q->where('used', '<=', $limit - $amount);
        }
        if ($q->update(['used' => DB::raw('used + '.(int) $amount), 'updated_at' => now()]) === 0) {
            throw new PlanLimitReached($metric, $limit);
        }
    }

    /** Give back a unit consumed for an operation that then failed (e.g. AI provider down). */
    public function refund(User $user, string $metric, int $amount = 1): void
    {
        if (!self::tablesReady()) {
            return;
        }
        DB::table('usage_counters')->where(['user_id' => $user->id, 'metric' => $metric, 'period' => self::period()])
            ->where('used', '>=', $amount)->update(['used' => DB::raw('used - '.(int) $amount)]);
    }

    /** Attachment storage in whole megabytes (rounded up). */
    public function storageMb(User $user): int
    {
        try {
            return (int) ceil(((int) \App\Models\Attachment::withoutGlobalScopes()->where('user_id', $user->id)->sum('size')) / 1048576);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Would adding $bytes exceed the plan's attachment storage? */
    public function ensureStorageFor(User $user, int $bytes): void
    {
        $limit = $this->limit($user, 'attachment_storage_mb');
        if ($limit === null) {
            return;
        }
        $used = (int) \App\Models\Attachment::withoutGlobalScopes()->where('user_id', $user->id)->sum('size');
        if ($used + $bytes > $limit * 1048576) {
            throw new PlanLimitReached('attachment_storage_mb', $limit);
        }
    }

    public function ensureCanCreate(User $user, string $metric): void
    {
        $limit = $this->limit($user, $metric);
        if ($limit !== null && $this->count($user, $metric) >= $limit) {
            throw new PlanLimitReached($metric, $limit);
        }
    }

    private function count(User $user, string $metric): int
    {
        $closed = ['completed', 'cancelled', 'canceled'];
        $query = match ($metric) {
            'active_goals' => Goal::withoutGlobalScopes(),
            'active_projects' => Project::withoutGlobalScopes(),
            'open_tasks' => Task::withoutGlobalScopes(),
            default => null,
        };
        return $query ? $query->where('user_id', $user->id)->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', $closed))->count() : 0;
    }

    /** @return array{plan:?Plan,subscription:?Subscription,metrics:array<string,array{used:int,limit:?int}>,features:array<string,bool>} */
    public function summary(User $user): array
    {
        $metrics = [];
        foreach (array_keys((array) config('billing.metrics')) as $m) {
            $metrics[$m] = ['used' => $this->used($user, $m), 'limit' => $this->limit($user, $m)];
        }
        $features = [];
        foreach ((array) config('billing.features') as $f) {
            $features[$f] = $this->canUse($user, $f);
        }
        return ['plan' => $this->plan($user), 'subscription' => $this->currentSubscription($user), 'metrics' => $metrics, 'features' => $features];
    }
}
