<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An imported external event. It marks busy time; it is never a planner task. */
final class CalendarEvent extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'calendar_connection_id', 'provider_event_id', 'title', 'starts_at', 'ends_at', 'all_day', 'is_busy'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean', 'is_busy' => 'boolean'];

    public function connection(): BelongsTo { return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id'); }
}
