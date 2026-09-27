<?php
declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\PlannerUserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Multi-user ownership for planner models.
 *
 * - creating: assigns user_id (explicit value > PlannerUserContext (Telegram) > Auth::id() (web/API)).
 * - global scope: whenever a planner owner is known, every query (including route model
 *   binding and relations) is restricted to that owner's rows. Console jobs such as the
 *   reminder worker have no owner and therefore keep seeing all rows.
 * - saving: foreign references listed in $plannerReferences must belong to the same owner,
 *   so no user can attach another user's goal/project/task by guessing an ID.
 */
trait BelongsToPlannerUser
{
    public static function bootBelongsToPlannerUser(): void
    {
        static::addGlobalScope('planner_owner', function (Builder $query): void {
            $ownerId = static::currentPlannerOwnerId();
            if ($ownerId !== null) {
                $query->where($query->getModel()->qualifyColumn('user_id'), $ownerId);
            }
        });

        // "saving" fires before "creating", so the owner is assigned here and the
        // reference check below always sees the final user_id.
        static::saving(function (Model $model): void {
            if (!$model->exists && $model->getAttribute('user_id') === null) {
                $ownerId = static::currentPlannerOwnerId();
                if ($ownerId !== null) {
                    $model->setAttribute('user_id', $ownerId);
                }
            }
            $model->assertPlannerReferencesOwned();
            if (!$model->exists) {
                $model->assertPlanAllowsCreation();
            }
        });

        static::created(function (Model $model): void {
            $event = property_exists($model, 'plannerFirstEvent') ? $model->plannerFirstEvent : null;
            if ($event && $model->getAttribute('user_id') !== null) {
                \App\Services\Analytics\ProductEvents::record(User::find($model->getAttribute('user_id')), $event, [], true);
            }
        });
    }

    /** Count-based plan limits (e.g. open tasks) — enforced here so web, API and Telegram all obey them. */
    public function assertPlanAllowsCreation(): void
    {
        $metric = property_exists($this, 'planLimitMetric') ? $this->planLimitMetric : null;
        $ownerId = $this->getAttribute('user_id');
        if ($metric === null || $ownerId === null) {
            return;
        }
        $owner = User::find($ownerId);
        if ($owner) {
            app(\App\Services\Billing\Entitlements::class)->ensureCanCreate($owner, $metric);
        }
    }

    public function initializeBelongsToPlannerUser(): void
    {
        if (!in_array('user_id', $this->fillable, true)) {
            $this->fillable[] = 'user_id';
        }
    }

    public static function currentPlannerOwnerId(): ?int
    {
        $id = PlannerUserContext::id();
        if ($id !== null) {
            return $id;
        }
        $authId = Auth::id();
        return $authId !== null ? (int) $authId : null;
    }

    /** @return array<string,class-string<Model>> */
    protected function plannerReferenceMap(): array
    {
        return property_exists($this, 'plannerReferences') ? $this->plannerReferences : [];
    }

    public function assertPlannerReferencesOwned(): void
    {
        $ownerId = $this->getAttribute('user_id');
        if ($ownerId === null) {
            return;
        }
        $errors = [];
        foreach ($this->plannerReferenceMap() as $attribute => $class) {
            $value = $this->getAttribute($attribute);
            if ($value === null || (!$this->isDirty($attribute) && !$this->isDirty('user_id') && $this->exists)) {
                continue;
            }
            $owned = $class::withoutGlobalScopes()
                ->whereKey($value)
                ->where('user_id', $ownerId)
                ->exists();
            if (!$owned) {
                $errors[$attribute] = 'The selected '.$attribute.' is invalid.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Timestamps are stored as wall-clock time in the application timezone. Values that carry
     * their own offset (ISO 8601 from the browser, a Carbon in the user's timezone) are converted
     * first, so "10:00+04:00" and "06:00Z" land on the same stored instant.
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)
            ->copy()->setTimezone((string) config('app.timezone'))
            ->format($this->getDateFormat());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Explicitly restrict to one owner, bypassing the ambient owner scope. */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->withoutGlobalScope('planner_owner')->where($this->qualifyColumn('user_id'), $userId);
    }
}
