<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'event', 'properties'];
    protected $casts = ['properties' => 'array', 'created_at' => 'datetime'];
}
