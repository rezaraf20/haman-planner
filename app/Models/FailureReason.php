<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FailureReason extends Model { protected $fillable=['code','name','preventable','severity']; }