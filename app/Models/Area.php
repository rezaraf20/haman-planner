<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Area extends Model {
    use BelongsToPlannerUser;

 protected $fillable=['user_id', 'name','type','description','status','sort_order'];
 public function goals(): HasMany { return $this->hasMany(Goal::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}