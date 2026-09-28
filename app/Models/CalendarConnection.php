<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A user's link to an external calendar. Tokens are encrypted at rest and never serialized. */
final class CalendarConnection extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = [
        'user_id', 'provider', 'account_email', 'access_token', 'refresh_token', 'token_expires_at', 'calendar_id', 'calendar_name',
        'export_calendar_id', 'import_enabled', 'export_enabled', 'status', 'last_synced_at', 'last_error',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'import_enabled' => 'boolean',
        'export_enabled' => 'boolean',
    ];

    public function events(): HasMany { return $this->hasMany(CalendarEvent::class); }
}
