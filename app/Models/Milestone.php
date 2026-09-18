<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Milestone extends Model {
 protected $fillable=['project_id','title','status','weight','progress','target_date','completed_at'];
 protected $casts=['target_date'=>'date','completed_at'=>'datetime','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function project(): BelongsTo { return $this->belongsTo(Project::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}