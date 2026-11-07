<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;

class DayRitual extends Model
{
    use BelongsToPlannerUser;

    public const KINDS = ['morning', 'evening'];

    protected $fillable = ['user_id', 'ritual_date', 'kind', 'status', 'energy', 'mood', 'intention', 'wins', 'notes',
        'top_task_ids', 'tomorrow_task_ids', 'moves', 'summary', 'completed_at'];

    protected $casts = [
        'ritual_date' => 'date:Y-m-d', 'top_task_ids' => 'array', 'tomorrow_task_ids' => 'array', 'moves' => 'array',
        'summary' => 'array', 'completed_at' => 'datetime', 'energy' => 'integer', 'mood' => 'integer',
    ];
}
