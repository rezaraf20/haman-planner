<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
class Note extends Model {
    use BelongsToPlannerUser;
 protected $fillable=['user_id', 'area_id','goal_id','project_id','task_id','title','content']; }