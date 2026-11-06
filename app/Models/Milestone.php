<?php
namespace App\Models;
use App\Models\Concerns\BelongsToPlannerUser;
use App\Models\Concerns\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Milestone extends Model {
    use BelongsToPlannerUser, HasLifecycle;

    /** Allowed statuses; the first is the default for a reopened item. */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    /** @var array<string,class-string> references that must belong to the same owner */
    protected array $plannerReferences = ['project_id'=>Project::class];

 protected $fillable=['user_id', 'project_id','title','status','weight','progress','target_date','completed_at','archived_at'];
 protected $casts=['archived_at'=>'datetime','target_date'=>'date','completed_at'=>'datetime','progress'=>'decimal:2','weight'=>'decimal:2'];
 public function project(): BelongsTo { return $this->belongsTo(Project::class); }
 public function tasks(): HasMany { return $this->hasMany(Task::class); }
}