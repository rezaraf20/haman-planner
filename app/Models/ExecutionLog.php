<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ExecutionLog extends Model {
 protected $fillable=['task_id','started_at','ended_at','duration_minutes','focus_level','energy_level','result','blocker','notes'];
 protected $casts=['started_at'=>'datetime','ended_at'=>'datetime'];
 public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}