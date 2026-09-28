<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Subscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'past_due', 'canceled', 'expired'];

    /** Statuses that grant the plan while inside the period (past_due: while inside the grace window). */
    public const LIVE = ['trialing', 'active', 'canceled'];

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_interval', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'canceled_at', 'ended_at', 'provider', 'provider_reference', 'expiry_notified_at',
        'provider_customer_id', 'auto_renew', 'past_due_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'expiry_notified_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'auto_renew' => 'boolean',
        'past_due_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    /**
     * Subscriptions that currently grant their plan:
     *  - trialing/active, or canceled but still inside the paid period;
     *  - provider-managed (auto_renew) ones for a short grace after the period end, so a late renewal
     *    webhook never drops a paying customer to the free plan;
     *  - past_due (the provider is retrying a failed renewal) for a grace counted from the failure.
     */
    public function scopeCurrent(Builder $q): Builder
    {
        $now = now();
        return $q->where(fn ($outer) => $outer
            ->where(fn ($w) => $w->whereIn('status', self::LIVE)
                ->where(fn ($p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>', $now)
                    ->orWhere(fn ($a) => $a->where('auto_renew', true)->where('current_period_end', '>', $now->copy()->subDays(self::webhookGraceDays())))))
            ->orWhere(fn ($w) => $w->where('status', 'past_due')
                ->where(fn ($p) => $p->where('past_due_at', '>', $now->copy()->subDays(self::pastDueGraceDays()))
                    ->orWhere(fn ($n) => $n->whereNull('past_due_at')->where('current_period_end', '>', $now->copy()->subDays(self::pastDueGraceDays()))))));
    }

    public static function pastDueGraceDays(): int
    {
        return max(0, (int) config('billing.past_due_grace_days', 7));
    }

    public static function webhookGraceDays(): int
    {
        return max(0, (int) config('billing.webhook_grace_days', 3));
    }

    /** When a past_due subscription loses access (grace counted from the failed renewal). */
    public function pastDueUntil(): ?\Illuminate\Support\Carbon
    {
        $from = $this->past_due_at ?? $this->current_period_end;
        return $from?->copy()->addDays(self::pastDueGraceDays());
    }

    public function isCurrent(): bool
    {
        if ($this->status === 'past_due') {
            return ($until = $this->pastDueUntil()) !== null && $until->isFuture();
        }
        if (!in_array($this->status, self::LIVE, true)) {
            return false;
        }
        if ($this->current_period_end === null || $this->current_period_end->isFuture()) {
            return true;
        }
        return $this->auto_renew && $this->current_period_end->copy()->addDays(self::webhookGraceDays())->isFuture();
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing' && $this->isCurrent();
    }
}
