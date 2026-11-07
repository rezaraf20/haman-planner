<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'name', 'color', 'icon'];

    public function tasks(): BelongsToMany { return $this->belongsToMany(Task::class, 'task_tag'); }
}
