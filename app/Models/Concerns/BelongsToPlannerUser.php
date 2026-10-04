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
            $model->fillRequiredColumnDefaults();
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

    private const NO_DEFAULT = "\0no-default";

    /** @var array<string,array<string,mixed>> table => [column => default or NO_DEFAULT] for NOT NULL columns */
    private static array $requiredColumnDefaults = [];

    /**
     * Forms send an untouched optional select as "" (→ null). For a NOT NULL column, null means
     * "not chosen": a new record gets the column's database default (status, priority, type,
     * importance…) and an existing record keeps its current value — instead of the query failing
     * with a server error.
     */
    public function fillRequiredColumnDefaults(): void
    {
        foreach (self::requiredColumnDefaults($this->getTable()) as $column => $default) {
            if (!array_key_exists($column, $this->getAttributes()) || $this->getAttributes()[$column] !== null) {
                continue;
            }
            if ($this->exists) {
                $this->setAttribute($column, $this->getOriginal($column)); // cleared required field: keep what it was
            } elseif ($default === self::NO_DEFAULT) {
                continue; // genuinely required: validation / the friendly database-error response covers it
            } elseif ($default === self::class) {
                unset($this->attributes[$column]); // not a plain literal: let the database apply it
            } else {
                $this->setAttribute($column, $default);
            }
        }
    }

    /** @return array<string,mixed> */
    private static function requiredColumnDefaults(string $table): array
    {
        if (!isset(self::$requiredColumnDefaults[$table])) {
            $out = [];
            try {
                foreach (\Illuminate\Support\Facades\Schema::getColumns($table) as $col) {
                    if (($col['nullable'] ?? true) || ($col['auto_increment'] ?? false) || in_array($col['name'], ['id', 'user_id', 'created_at', 'updated_at'], true)) {
                        continue;
                    }
                    $out[$col['name']] = ($col['default'] ?? null) === null ? self::NO_DEFAULT : self::literalDefault((string) $col['default']);
                }
            } catch (\Throwable) {
                $out = [];
            }
            self::$requiredColumnDefaults[$table] = $out;
        }
        return self::$requiredColumnDefaults[$table];
    }

    /** Turns a column default such as 'p2'::character varying, 0, 1.00 or true into a PHP value. */
    private static function literalDefault(string $raw): mixed
    {
        $raw = trim($raw);
        if (preg_match("/^'((?:[^']|'')*)'(?:::[\\w\\s]+)?$/", $raw, $m)) {
            return str_replace("''", "'", $m[1]);
        }
        if (preg_match('/^\(?(-?\d+(?:\.\d+)?)\)?(?:::[\w\s]+)?$/', $raw, $m)) {
            return str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1];
        }
        $lower = strtolower($raw);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        return self::class; // expression (now(), nextval…) → database applies it
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
