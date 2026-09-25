<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Goal extends Model {
    use BelongsToPlannerUser;

 protected $fillable=['user_id', 'area_id','title','description','status','start_date','target_date','importance','weight','progress','health','success_criteria'];
 protected $casts=['start_date'=>'date','target_date'=>'date','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function area(): BelongsTo { return $this->belongsTo(Area::class); }
 public function projects(): HasMany { return $this->hasMany(Project::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}