<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
final class User extends Authenticatable {
 use Notifiable;
 protected $fillable=['name','email','password','is_admin','is_active','telegram_chat_id','telegram_username','telegram_linked_at'];
 protected $hidden=['password','remember_token'];
 protected function casts(): array { return ['email_verified_at'=>'datetime','telegram_linked_at'=>'datetime','last_seen_at'=>'datetime','is_admin'=>'boolean','is_active'=>'boolean']; }
 public function apiTokens(){ return $this->hasMany(ApiToken::class); }
 /** Records activity at most every 5 minutes, without touching updated_at. */
 public function markSeen(): void { if($this->last_seen_at===null||$this->last_seen_at->lt(now()->subMinutes(5))){ $now=now(); static::query()->whereKey($this->id)->toBase()->update(['last_seen_at'=>$now]); $this->last_seen_at=$now; } }
 public function supportTickets(){ return $this->hasMany(SupportTicket::class); }
 public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void { $this->notify(new \App\Notifications\ResetPasswordNotification($token)); }
}