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

    public function candidates(string $entity, string $query, int $limit = 5): array
    {
        $model = $this->modelFor($entity);
        if ($model === null || trim($query) === '') {
            return [];
        }

        $tokens = preg_split('/\\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $model::query()
            ->where(function ($builder) use ($tokens, $query): void {
                if ($tokens === []) {
                    $builder->where('title', 'like', '%'.trim($query).'%');
                    return;
                }
                foreach ($tokens as $token) {
                    $builder->orWhere('title', 'like', '%'.$token.'%');
                }
            })
            ->orderByDesc('id')
            ->limit(max(1, min(10, $limit)))
            ->get(['id', 'title'])
            ->map(fn (Model $item): array => ['id' => $item->id, 'title' => $item->title])
            ->all();
    }

    public function resolveWithStatus(string $entity, array $args): array
    {
        $model = $this->modelFor($entity);
        if ($model === null) {
            return ['status' => 'not_found', 'entity' => $entity, 'candidates' => []];
        }

        $query = $args[$entity] ?? $args[$entity.'_title'] ?? $args['title'] ?? null;
        $id = $args[$entity.'_id'] ?? null;

        if ($id !== null && ctype_digit((string) $id)) {
            $found = $model::find((int) $id);
            return $found
                ? ['status' => 'resolved', 'entity' => $entity, 'model' => $found]
                : ['status' => 'not_found', 'entity' => $entity, 'candidates' => []];
        }

        $needle = trim((string) $query);
        if ($needle === '') {
            return ['status' => 'not_found', 'entity' => $entity, 'candidates' => []];
        }

        $exact = $model::where('title', $needle)->latest('id')->first();
        if ($exact) {
            return ['status' => 'resolved', 'entity' => $entity, 'model' => $exact];
        }

        $items = $this->candidates($entity, $needle, 5);
        if ($items === []) {
            return ['status' => 'not_found', 'entity' => $entity, 'candidates' => []];
        }

        $needleNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $needle) ?? $needle);
        $ranked = [];

        foreach ($items as $item) {
            $titleNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $item['title']) ?? $item['title']);
            similar_text($needleNorm, $titleNorm, $percent);
            $ranked[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'score' => round($percent / 100, 3),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $ranked[0];
        $second = $ranked[1] ?? null;

        if ($best['score'] >= 0.82 && $second === null) {
            return [
                'status' => 'resolved',
                'entity' => $entity,
                'model' => $model::find($best['id']),
                'candidates' => $ranked,
            ];
        }

        return ['status' => 'ambiguous', 'entity' => $entity, 'candidates' => $ranked];
    }

    private function resolve(string $model, mixed $id, mixed $title): ?Model
    {
        $entity = match ($model) {
            Task::class => 'task',
            Goal::class => 'goal',
            Project::class => 'project',
            Milestone::class => 'milestone',
        };

        $idKey = $entity.'_id';
        $result = $this->resolveWithStatus(
            $entity,
            array_filter([$idKey => $id, 'title' => $title], fn ($value) => $value !== null)
        );

        return $result['status'] === 'resolved' ? $result['model'] : null;
    }

    private function modelFor(string $entity): ?string
    {
        return match ($entity) {
            'task' => Task::class,
            'goal' => Goal::class,
            'project' => Project::class,
            'milestone' => Milestone::class,
            default => null,
        };
    }
}
