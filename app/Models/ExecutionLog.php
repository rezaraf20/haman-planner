<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ExecutionLog extends Model {
    use BelongsToPlannerUser;

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['task_id'=>Task::class];

 protected $fillable=['user_id', 'task_id','started_at','ended_at','duration_minutes','focus_level','energy_level','result','blocker','notes'];
 protected $casts=['started_at'=>'datetime','ended_at'=>'datetime'];
 public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}