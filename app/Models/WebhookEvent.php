<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Idempotency log for provider webhooks: one row per (provider, event id). No payload is stored. */
final class WebhookEvent extends Model
{
    public const STATUSES = ['received', 'processed', 'ignored', 'failed'];

    protected $fillable = ['provider', 'event_id', 'type', 'status', 'error', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
