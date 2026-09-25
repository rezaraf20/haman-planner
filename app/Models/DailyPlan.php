<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DailyPlan extends Model {
    use BelongsToPlannerUser;

 protected $fillable=['user_id', 'plan_date','available_minutes','planned_minutes','completed_minutes','buffer_minutes','focus_level','energy_level','notes'];
 protected $casts=['plan_date'=>'date'];
 public function scheduleBlocks(): HasMany { return $this->hasMany(ScheduleBlock::class); }
}