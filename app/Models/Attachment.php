<?php
declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToPlannerUser;
use Illuminate\Database\Eloquent\Model;

/** A private file attached to a task or project. `path` is never exposed to clients. */
final class Attachment extends Model
{
    use BelongsToPlannerUser;

    public const TYPES = ['task' => Task::class, 'project' => Project::class];

    protected $fillable = ['user_id', 'attachable_type', 'attachable_id', 'disk', 'path', 'original_name', 'mime', 'extension', 'size', 'sha256'];

    protected $hidden = ['disk', 'path', 'sha256'];

    protected $casts = ['size' => 'integer', 'attachable_id' => 'integer'];
}
