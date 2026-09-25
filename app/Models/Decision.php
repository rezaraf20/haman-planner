<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
class Decision extends Model {
    use BelongsToPlannerUser;

 protected $fillable=['user_id', 'area_id','title','decision','rationale','decided_at'];
 protected $casts=['decided_at'=>'datetime'];
}