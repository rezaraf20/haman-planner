<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitEntry extends Model
{
    use BelongsToPlannerUser;

    /** Referenced rows must belong to the same user (enforced on save). */
    protected array $plannerReferences = ['habit_id' => Habit::class];

    protected $fillable = ['user_id', 'habit_id', 'entry_date', 'completed', 'value', 'note'];

    protected $attributes = ['completed' => false];

    protected $casts = ['entry_date' => 'date:Y-m-d', 'completed' => 'boolean', 'value' => 'float'];

    public function habit(): BelongsTo { return $this->belongsTo(Habit::class); }
}
