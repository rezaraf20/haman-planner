<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AiInteraction extends Model {
 public $timestamps=false;
 protected $fillable=['provider','model','intent','input_hash','input_payload','output_payload','confidence','status','created_at'];
 protected $casts=['input_payload'=>'array','output_payload'=>'array','confidence'=>'decimal:4','created_at'=>'datetime'];
}