<?php
namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskDependency extends Model
{
    use BelongsToPlannerUser;

    protected $fillable = ['user_id', 'task_id','depends_on_task_id','type'];
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function dependsOn(): BelongsTo { return $this->belongsTo(Task::class, 'depends_on_task_id'); }
}
