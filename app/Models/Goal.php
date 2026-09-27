<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Goal extends Model {
    use BelongsToPlannerUser;

    /** Plan limit applied when a new record is created (see Entitlements). */
    protected string $planLimitMetric = 'active_goals';

    /** Product analytics event recorded the first time a user creates one. */
    protected string $plannerFirstEvent = 'first_goal';

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['area_id'=>Area::class];

 protected $fillable=['user_id', 'area_id','title','description','status','start_date','target_date','importance','weight','progress','health','success_criteria'];
 protected $casts=['start_date'=>'date','target_date'=>'date','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function area(): BelongsTo { return $this->belongsTo(Area::class); }
 public function projects(): HasMany { return $this->hasMany(Project::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}