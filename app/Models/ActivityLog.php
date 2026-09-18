<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_type','actor_id','action','entity_type','entity_id','before','after','created_at'];
    protected $casts = ['before'=>'array','after'=>'array','created_at'=>'datetime'];
}
