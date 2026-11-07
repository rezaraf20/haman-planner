<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'name', 'icon', 'definition', 'sort_order'];
    protected $casts = ['definition' => 'array', 'sort_order' => 'integer'];
}
