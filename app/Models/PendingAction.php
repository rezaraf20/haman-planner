<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PendingAction extends Model
{
    protected $fillable = ['chat_id', 'intent', 'payload', 'status', 'expires_at'];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
