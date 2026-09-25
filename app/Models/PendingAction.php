<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;

final class PendingAction extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'chat_id', 'intent', 'payload', 'status', 'expires_at'];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
