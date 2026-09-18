<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Decision extends Model {
 protected $fillable=['area_id','title','decision','rationale','decided_at'];
 protected $casts=['decided_at'=>'datetime'];
}