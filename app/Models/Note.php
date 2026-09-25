<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
class Note extends Model {
    use BelongsToPlannerUser;

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['area_id'=>Area::class,'goal_id'=>Goal::class,'project_id'=>Project::class,'task_id'=>Task::class];
 protected $fillable=['user_id', 'area_id','goal_id','project_id','task_id','title','content']; }