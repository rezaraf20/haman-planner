<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Review extends Model {
 protected $fillable=['type','period_start','period_end','summary','metrics_json','actions_json'];
 protected $casts=['period_start'=>'date','period_end'=>'date','metrics_json'=>'array','actions_json'=>'array'];
}