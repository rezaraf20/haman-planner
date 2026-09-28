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
     * Subscriptions that currently grant their plan: trialing/active, canceled but still inside the
     * paid period, or past_due (the provider is retrying the renewal charge) within the grace window.
     */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where(fn ($outer) => $outer
            ->where(fn ($w) => $w->whereIn('status', self::LIVE)
                ->where(fn ($p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>', now())))
            ->orWhere(fn ($w) => $w->where('status', 'past_due')
                ->where('current_period_end', '>', now()->subDays(self::pastDueGraceDays()))));
    }

    public static function pastDueGraceDays(): int
    {
        return max(0, (int) config('billing.past_due_grace_days', 7));
    }

    public function isCurrent(): bool
    {
        if ($this->status === 'past_due') {
            return $this->current_period_end !== null && $this->current_period_end->gt(now()->subDays(self::pastDueGraceDays()));
        }
        return in_array($this->status, self::LIVE, true)
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing' && $this->isCurrent();
    }
}
