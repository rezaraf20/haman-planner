<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A behaviour to build or reduce. Scheduling and streak rules live in HabitService. */
class Habit extends Model
{
    use BelongsToPlannerUser;

    public const KINDS = ['build', 'reduce'];
    public const MEASURES = ['binary', 'numeric'];
    public const FREQUENCIES = ['daily', 'weekdays', 'weekly', 'interval'];

    /** Plan limit applied when a habit is created (see Entitlements). */
    protected string $planLimitMetric = 'active_habits';

    /** Referenced rows must belong to the same user (enforced on save). */
    protected array $plannerReferences = ['goal_id' => Goal::class, 'area_id' => Area::class];

    protected $fillable = ['user_id', 'goal_id', 'area_id', 'title', 'description', 'icon', 'color', 'kind', 'measure',
        'target_value', 'unit', 'frequency', 'weekdays', 'times_per_week', 'interval_days', 'start_date', 'status',
        'pauses', 'archived_at', 'sort_order'];

    /** Same defaults as the table, so a freshly created model behaves correctly before a refresh. */
    protected $attributes = ['kind' => 'build', 'measure' => 'binary', 'frequency' => 'daily', 'status' => 'active', 'sort_order' => 0];

    protected $casts = ['weekdays' => 'array', 'pauses' => 'array', 'start_date' => 'date', 'archived_at' => 'datetime',
        'target_value' => 'float', 'times_per_week' => 'integer', 'interval_days' => 'integer', 'sort_order' => 'integer'];

    public function entries(): HasMany { return $this->hasMany(HabitEntry::class); }
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function area(): BelongsTo { return $this->belongsTo(Area::class); }
}
