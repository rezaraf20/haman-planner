<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
class AiInteraction extends Model {
    use BelongsToPlannerUser;

 public $timestamps=false;
 protected $fillable=['user_id', 'provider','model','intent','input_hash','input_payload','output_payload','confidence','status','created_at'];
 protected $casts=['input_payload'=>'array','output_payload'=>'array','confidence'=>'decimal:4','created_at'=>'datetime'];
}