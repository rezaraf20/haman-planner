<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Task extends Model
{
    protected $fillable=['area_id','goal_id','project_id','milestone_id','parent_task_id','title','description','status','priority','importance','weight','progress','estimated_minutes','actual_minutes','planned_start','planned_end','deadline','energy_level','focus_level','failure_reason'];
    protected $casts=['planned_start'=>'datetime','planned_end'=>'datetime','deadline'=>'datetime','progress'=>'decimal:2','weight'=>'decimal:2'];

    public function area(): BelongsTo { return $this->belongsTo(Area::class); }
    public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function milestone(): BelongsTo { return $this->belongsTo(Milestone::class); }
    public function parent(): BelongsTo { return $this->belongsTo(Task::class,'parent_task_id'); }
    public function children(): HasMany { return $this->hasMany(Task::class,'parent_task_id'); }
    public function dependencies(): HasMany { return $this->hasMany(TaskDependency::class); }
    public function dependents(): HasMany { return $this->hasMany(TaskDependency::class,'depends_on_task_id'); }
    public function executionLogs(): HasMany { return $this->hasMany(ExecutionLog::class); }
}
