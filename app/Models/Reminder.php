<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    protected $attributes = ['type' => 'telegram', 'attempts' => 0, 'max_attempts' => 3];
    protected $fillable = ['task_id','type','scheduled_at','next_attempt_at','status','attempts','max_attempts','payload'];

    protected $casts = [
        'scheduled_at'=>'datetime','next_attempt_at'=>'datetime',
        'payload' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
