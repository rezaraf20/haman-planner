<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UsageCounter extends Model
{
    protected $fillable = ['user_id', 'metric', 'period', 'used'];
    protected $casts = ['used' => 'integer'];
}
