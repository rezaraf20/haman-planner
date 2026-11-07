<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One focus timer. Elapsed time is always computed from server timestamps (reload-safe). */
class FocusSession extends Model
{
    use BelongsToPlannerUser;

    public const ACTIVE = ['running', 'paused'];

    /** Referenced rows must belong to the same user (enforced on save). */
    protected array $plannerReferences = ['task_id' => Task::class, 'project_id' => Project::class];

    protected $fillable = ['user_id', 'task_id', 'project_id', 'planned_minutes', 'status', 'started_at', 'paused_at',
        'paused_seconds', 'ended_at', 'actual_seconds', 'execution_log_id', 'note', 'client_uuid'];

    protected $attributes = ['status' => 'running', 'paused_seconds' => 0];

    protected $casts = ['started_at' => 'datetime', 'paused_at' => 'datetime', 'ended_at' => 'datetime',
        'planned_minutes' => 'integer', 'paused_seconds' => 'integer', 'actual_seconds' => 'integer'];

    protected $appends = ['elapsed_seconds', 'remaining_seconds'];

    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Seconds of actual focus: wall time minus pauses, frozen while paused or after the end. */
    public function getElapsedSecondsAttribute(): int
    {
        if ($this->actual_seconds !== null && !$this->isActive()) {
            return $this->actual_seconds;
        }
        $until = $this->ended_at ?? $this->paused_at ?? now();
        return max(0, (int) $this->started_at->diffInSeconds($until, false) - (int) $this->paused_seconds);
    }

    public function getRemainingSecondsAttribute(): int
    {
        return max(0, $this->planned_minutes * 60 - $this->elapsed_seconds);
    }
}
