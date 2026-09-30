<?php
declare(strict_types=1);

namespace App\Models;

use App\Domain\Planner\RecurrenceRule;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring task (series): the template fields copied into every occurrence plus the rule.
 * Occurrences are ordinary tasks (tasks.recurring_task_id + occurrence_date).
 */
final class RecurringTask extends Model
{
    use BelongsToPlannerUser;

    /** Plan limit applied when a new series is created (see Entitlements). */
    protected string $planLimitMetric = 'active_recurring';

    /** Fields copied from the series into each occurrence. */
    public const TEMPLATE_FIELDS = ['title', 'description', 'area_id', 'goal_id', 'project_id', 'milestone_id', 'priority', 'importance', 'weight', 'estimated_minutes'];

    /** Fields that change which dates or times occurrences have. */
    public const RULE_FIELDS = ['frequency', 'calendar', 'interval', 'by_weekday', 'by_month_day', 'by_month', 'starts_on', 'ends_on', 'max_occurrences', 'time_of_day', 'timezone'];

    /** @var array<string,class-string> */
    protected array $plannerReferences = ['area_id' => Area::class, 'goal_id' => Goal::class, 'project_id' => Project::class, 'milestone_id' => Milestone::class];

    protected $fillable = [
        'user_id', 'area_id', 'goal_id', 'project_id', 'milestone_id', 'title', 'description', 'priority', 'importance', 'weight',
        'estimated_minutes', 'frequency', 'calendar', 'interval', 'by_weekday', 'by_month_day', 'by_month', 'starts_on', 'ends_on',
        'max_occurrences', 'time_of_day', 'timezone', 'status', 'generated_until', 'occurrences_generated', 'stopped_at',
    ];

    protected $casts = [
        'by_weekday' => 'array',
        'starts_on' => 'date:Y-m-d',
        'ends_on' => 'date:Y-m-d',
        'generated_until' => 'date:Y-m-d',
        'stopped_at' => 'datetime',
        'weight' => 'decimal:2',
        'interval' => 'integer',
        'by_month_day' => 'integer',
        'by_month' => 'integer',
        'max_occurrences' => 'integer',
        'importance' => 'integer',
        'estimated_minutes' => 'integer',
        'occurrences_generated' => 'integer',
    ];

    public function occurrences(): HasMany { return $this->hasMany(Task::class); }
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function milestone(): BelongsTo { return $this->belongsTo(Milestone::class); }

    public function rule(): RecurrenceRule
    {
        return new RecurrenceRule(
            (string) $this->frequency,
            max(1, (int) $this->interval),
            array_values(array_map('intval', (array) ($this->by_weekday ?? []))),
            $this->by_month_day,
            $this->by_month,
            $this->starts_on->toDateString(),
            $this->ends_on?->toDateString(),
            $this->max_occurrences,
            (string) ($this->calendar ?: 'gregorian'),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return array<string,mixed> the fields an occurrence starts with */
    public function templateAttributes(): array
    {
        // Nulls are dropped for columns that have database defaults on tasks.
        return array_filter($this->only(self::TEMPLATE_FIELDS), fn ($v, $k) => $v !== null || in_array($k, ['description', 'area_id', 'goal_id', 'project_id', 'milestone_id'], true), ARRAY_FILTER_USE_BOTH);
    }
}
