<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use BelongsToPlannerUser;

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['task_id'=>Task::class];

    protected $attributes = ['type' => 'telegram', 'attempts' => 0, 'max_attempts' => 3];
    protected $fillable = ['user_id', 'task_id','type','scheduled_at','next_attempt_at','status','attempts','max_attempts','payload'];

    protected $casts = [
        'scheduled_at'=>'datetime','next_attempt_at'=>'datetime',
        'payload' => 'array',
    ];

    /** Exposed for the edit form and lists (they live inside payload). */
    protected $appends = ['message', 'title'];

    public function getMessageAttribute(): ?string
    {
        $m = is_array($this->payload) ? ($this->payload['message'] ?? null) : null;
        return $m !== null && $m !== '' ? (string) $m : null;
    }

    /** What a list shows: the message, else the task's title. */
    public function getTitleAttribute(): ?string
    {
        return $this->message ?? ($this->relationLoaded('task') ? $this->task?->title : null);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
