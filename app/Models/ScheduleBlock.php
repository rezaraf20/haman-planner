<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ScheduleBlock extends Model {
 protected $fillable=['daily_plan_id','task_id','starts_at','ends_at','source','status'];
 protected $casts=['starts_at'=>'datetime','ends_at'=>'datetime'];
 public function dailyPlan(): BelongsTo { return $this->belongsTo(DailyPlan::class); }
 public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}