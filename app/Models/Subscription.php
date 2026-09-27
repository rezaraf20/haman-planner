<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Subscription extends Model
{
    public const STATUSES = ['trialing', 'active', 'canceled', 'expired'];

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_interval', 'trial_ends_at', 'current_period_start', 'current_period_end',
        'cancel_at_period_end', 'canceled_at', 'ended_at', 'provider', 'provider_reference', 'expiry_notified_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
        'expiry_notified_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    /** Subscriptions that currently grant their plan: trialing/active, or canceled but still inside the paid period. */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereIn('status', ['trialing', 'active', 'canceled'])
            ->where(fn ($w) => $w->whereNull('current_period_end')->orWhere('current_period_end', '>', now()));
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, ['trialing', 'active', 'canceled'], true)
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }

    public function onTrial(): bool
    {
        return $this->status === 'trialing' && $this->isCurrent();
    }
}
