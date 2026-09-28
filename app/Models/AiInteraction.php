<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
class AiInteraction extends Model {
    use BelongsToPlannerUser;

 public $timestamps=false;
 protected $fillable=['user_id', 'provider','model','intent','input_hash','input_payload','output_payload','confidence','status','created_at','request_id'];
 protected $casts=['input_payload'=>'array','output_payload'=>'array','confidence'=>'decimal:4','created_at'=>'datetime'];

 /** Every AI call gets an ID that also appears in the logs (the HTTP request ID when there is one). */
 protected static function booted(): void
 {
     static::creating(function (self $m): void {
         if (!filled($m->request_id)) {
             $m->request_id = (string) (\Illuminate\Support\Facades\Context::get('request_id') ?? \Illuminate\Support\Str::uuid());
         }
     });
 }
}