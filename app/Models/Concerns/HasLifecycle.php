<?php
declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Open → completed/cancelled → archived, for goals, projects, milestones and areas.
 *
 * A model declares its allowed statuses in STATUSES (first = default for "reopen").
 * "Open" means not completed/cancelled and not archived; archived items are hidden from the
 * normal lists, the bot and AI context, and only appear in the Archive tab.
 */
trait HasLifecycle
{
    /** Statuses that mean the work is finished. */
    public const CLOSED_STATUSES = ['completed', 'cancelled'];

    public static function bootHasLifecycle(): void
    {
        // Keep completed_at in step with the status, whichever way it changes (web, bot, AI).
        static::saving(function ($model) {
            if (!$model->isDirty('status') || !in_array('completed_at', $model->getFillable(), true)) {
                return;
            }
            if ($model->status === 'completed') {
                $model->completed_at ??= now();
            } else {
                $model->completed_at = null;
            }
        });
    }

    /** Validation list for status: the model's statuses plus the value it already has (legacy data). */
    public static function statusRule(?string $current = null): string
    {
        $list = static::STATUSES;
        if ($current !== null && $current !== '' && !in_array($current, $list, true)) $list[] = $current;
        return 'in:'.implode(',', $list);
    }

    public static function statuses(): array
    {
        return static::STATUSES;
    }

    public function scopeOpen(Builder $q): Builder
    {
        $table = $q->getModel()->getTable();
        return $q->whereNull($table.'.archived_at')
            ->where(fn ($w) => $w->whereNull($table.'.status')->orWhereNotIn($table.'.status', self::CLOSED_STATUSES));
    }

    public function scopeNotArchived(Builder $q): Builder
    {
        return $q->whereNull($q->getModel()->getTable().'.archived_at');
    }

    /** open | closed | archived | all (anything else = all except archived) */
    public function scopeInState(Builder $q, ?string $state): Builder
    {
        $table = $q->getModel()->getTable();
        return match ($state) {
            'open' => $this->scopeOpen($q),
            'closed' => $q->whereNull($table.'.archived_at')->whereIn($table.'.status', self::CLOSED_STATUSES),
            'archived' => $q->whereNotNull($table.'.archived_at'),
            'all' => $q,
            default => $q->whereNull($table.'.archived_at'),
        };
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
