<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Project extends Model {
    use BelongsToPlannerUser;

 protected $fillable=['user_id', 'goal_id','title','description','status','importance','weight','progress','health','estimated_minutes','actual_minutes','start_date','target_date'];
 protected $casts=['start_date'=>'date','target_date'=>'date','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
 public function milestones(): HasMany { return $this->hasMany(Milestone::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}