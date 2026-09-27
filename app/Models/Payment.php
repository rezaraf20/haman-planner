<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Payment extends Model
{
    public const STATUSES = ['pending', 'paid', 'failed', 'canceled', 'refunded'];

    protected $fillable = [
        'user_id', 'subscription_id', 'plan_id', 'billing_interval', 'provider', 'provider_reference', 'transaction_reference',
        'amount', 'currency', 'status', 'failure_reason', 'customer_email', 'meta', 'paid_at',
    ];

    protected $casts = ['meta' => 'array', 'paid_at' => 'datetime', 'amount' => 'integer'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function invoice(): HasOne { return $this->hasOne(Invoice::class); }
}
