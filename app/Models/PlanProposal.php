<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;

/** A proposed set of plan changes. Nothing in it is applied until the user confirms. */
final class PlanProposal extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'kind', 'status', 'period_start', 'period_end', 'metrics', 'actions', 'applied_actions', 'summary', 'ai_used', 'request_id', 'expires_at', 'applied_at'];

    protected $casts = [
        'metrics' => 'array',
        'actions' => 'array',
        'applied_actions' => 'array',
        'ai_used' => 'boolean',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'expires_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'pending' && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
