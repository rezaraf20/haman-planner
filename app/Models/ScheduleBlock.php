<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ScheduleBlock extends Model {
    use BelongsToPlannerUser;

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['daily_plan_id'=>DailyPlan::class,'task_id'=>Task::class];

 protected $fillable=['user_id', 'daily_plan_id','task_id','starts_at','ends_at','source','status','kind','is_fixed','title','external_event_id','external_synced_at'];
 protected $casts=['starts_at'=>'datetime','ends_at'=>'datetime','is_fixed'=>'boolean','external_synced_at'=>'datetime'];
 public const KINDS = ['task', 'focus', 'break', 'buffer'];
 public function dailyPlan(): BelongsTo { return $this->belongsTo(DailyPlan::class); }
 public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}