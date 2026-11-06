<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Area extends Model {
    use BelongsToPlannerUser, HasLifecycle;

    /** Allowed statuses; the first is the default for a reopened item. */
    public const STATUSES = ['active'];

 protected $fillable=['user_id', 'name','type','description','status','sort_order','archived_at'];
 protected $casts=['archived_at'=>'datetime'];
 public function goals(): HasMany { return $this->hasMany(Goal::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}