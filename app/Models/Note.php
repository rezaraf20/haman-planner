<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Note extends Model { protected $fillable=['area_id','goal_id','project_id','task_id','title','content']; }