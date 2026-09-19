<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class ApiToken extends Model {
 protected $fillable=['user_id','name','token_hash','token_prefix','last_used_at','expires_at'];
 protected function casts(): array { return ['last_used_at'=>'datetime','expires_at'=>'datetime']; }
 public function user(){ return $this->belongsTo(User::class); }
}