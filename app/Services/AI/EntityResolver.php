<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

final class EntityResolver
{
    public function resolveTask(array $args): ?Task
    {
        return $this->resolve(Task::class, $args['task_id'] ?? null, $args['title'] ?? $args['task'] ?? null);
    }

    public function resolveGoal(array $args): ?Goal
    {
        return $this->resolve(Goal::class, $args['goal_id'] ?? null, $args['goal'] ?? $args['goal_title'] ?? null);
    }

    public function resolveProject(array $args): ?Project
    {
        return $this->resolve(Project::class, $args['project_id'] ?? null, $args['project'] ?? $args['project_title'] ?? null);
    }

    public function resolveMilestone(array $args): ?Milestone
    {
        return $this->resolve(Milestone::class, $args['milestone_id'] ?? null, $args['milestone'] ?? $args['milestone_title'] ?? null);
    }

    private function resolve(string $model, mixed $id, mixed $title): ?Model
    {
        if ($id !== null && ctype_digit((string) $id)) {
            return $model::find((int) $id);
        }

        $needle = trim((string) $title);
        if ($needle === '') return null;

        $exact = $model::where('title', $needle)->latest('id')->first();
        if ($exact) return $exact;

        $candidates = $model::query()
            ->where('title', 'like', '%'.$needle.'%')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($candidates->count() === 1) return $candidates->first();

        $needleNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $needle) ?? $needle);
        $ranked = [];
        foreach ($candidates as $candidate) {
            $titleNorm = mb_strtolower(preg_replace('/\s+/u', ' ', (string) $candidate->title) ?? $candidate->title);
            similar_text($needleNorm, $titleNorm, $percent);
            $ranked[] = ['model' => $candidate, 'score' => $percent / 100];
        }

        usort($ranked, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;

        // Never guess between similarly matching entities. Require both a strong
        // match and a meaningful gap from the next candidate.
        if ($best === null || $best['score'] < 0.82) return null;
        if ($second !== null && ($best['score'] - $second['score']) < 0.10) return null;

        return $best['model'];
    }
}
