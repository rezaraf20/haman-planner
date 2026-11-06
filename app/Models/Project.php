<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Project extends Model {
    use BelongsToPlannerUser, HasLifecycle;

    /** Allowed statuses; the first is the default for a reopened item. */
    public const STATUSES = ['active', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /** Plan limit applied when a new record is created (see Entitlements). */
    protected string $planLimitMetric = 'active_projects';

    /** Product analytics event recorded the first time a user creates one. */
    protected string $plannerFirstEvent = 'first_project';

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['goal_id'=>Goal::class];

 protected $fillable=['user_id', 'goal_id','title','description','status','importance','weight','progress','health','estimated_minutes','actual_minutes','start_date','target_date','archived_at','completed_at'];
 protected $casts=['archived_at'=>'datetime','completed_at'=>'datetime','start_date'=>'date','target_date'=>'date','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function goal(): BelongsTo { return $this->belongsTo(Goal::class); }
 public function milestones(): HasMany { return $this->hasMany(Milestone::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}