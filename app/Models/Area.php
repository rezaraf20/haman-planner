<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Area extends Model {
 protected $fillable=['name','type','description','status','sort_order'];
 public function goals(): HasMany { return $this->hasMany(Goal::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}