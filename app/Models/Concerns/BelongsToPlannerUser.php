<?php
declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\PlannerUserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Assigns planner ownership automatically on create.
 *
 * Precedence: explicitly supplied user_id > PlannerUserContext (Telegram) > Auth::id() (web).
 * When no owner can be determined the column is left null.
 */
trait BelongsToPlannerUser
{
    public static function bootBelongsToPlannerUser(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('user_id') !== null) {
                return;
            }
            $ownerId = PlannerUserContext::id() ?? Auth::id();
            if ($ownerId !== null) {
                $model->setAttribute('user_id', (int) $ownerId);
            }
        });
    }

    public function initializeBelongsToPlannerUser(): void
    {
        if (!in_array('user_id', $this->fillable, true)) {
            $this->fillable[] = 'user_id';
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Restrict a query to records owned by the given user. */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $userId);
    }
}
