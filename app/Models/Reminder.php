<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Reminder extends Model {
 protected $fillable=['task_id','type','scheduled_at','status','payload'];
 protected $casts=['scheduled_at'=>'datetime','payload'=>'array'];
}