<?php
declare(strict_types=1);

namespace App\Services\Planner;

use App\Models\Task;
use App\Models\User;
use App\Support\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Duration suggestions from the user's own history. Read-only: it never writes an estimate.
 *
 * 1. "similar": at least MIN_SIMILAR completed tasks with recorded actual time that share title
 *    words / project / tags with this task → the median of their actual minutes.
 * 2. "factor": the task already has an estimate and the user's estimate accuracy is known
 *    (PlanInsightsService) → estimate × measured factor.
 * 3. otherwise no suggestion, with the reason.
 */
final class EstimateService
{
    public const MIN_SIMILAR = 3;
    private const STOP = ['the', 'and', 'for', 'with', 'from', 'into', 'task', 'this', 'that', 'برای', 'این', 'آن', 'های', 'کار', 'کارهای', 'روی', 'with', 'about'];

    public function __construct(private readonly PlanInsightsService $insights) {}

    /** @param array{title:string,project_id?:?int,tag_ids?:array,estimated_minutes?:?int,exclude_id?:?int} $in */
    public function suggest(User $user, array $in): array
    {
        $tokens = self::tokens((string) $in['title']);
        $tags = array_map('intval', (array) ($in['tag_ids'] ?? []));
        $project = isset($in['project_id']) ? (int) $in['project_id'] : null;

        $history = Task::query()->ownedBy($user->id)->where('status', 'completed')->where('actual_minutes', '>', 0)
            ->where('completed_at', '>=', LocalDate::db(CarbonImmutable::now()->subDays(180)))
            ->when(!empty($in['exclude_id']), fn ($q) => $q->whereKeyNot((int) $in['exclude_id']))
            ->latest('completed_at')->limit(500)->get(['id', 'title', 'project_id', 'actual_minutes', 'estimated_minutes']);
        $tagMap = $tags && $history->isNotEmpty()
            ? DB::table('task_tag')->whereIn('task_id', $history->pluck('id'))->whereIn('tag_id', $tags)->get()->groupBy('task_id')->map->count()
            : collect();

        $scored = $history->map(function (Task $t) use ($tokens, $project, $tagMap) {
            $other = self::tokens((string) $t->title);
            $common = count(array_intersect($tokens, $other));
            $union = count(array_unique(array_merge($tokens, $other)));
            $score = $union ? $common / $union : 0.0;
            if ($project && $t->project_id === $project) $score += 0.25;
            $score += 0.2 * (int) ($tagMap[$t->id] ?? 0);
            return ['task' => $t, 'score' => $score, 'words' => $common];
        })->filter(fn ($x) => $x['words'] > 0 && $x['score'] >= 0.3)->sortByDesc('score')->take(10)->values();

        $factor = $this->insights->planVsActual($user, 90)['estimation'];
        $current = (int) ($in['estimated_minutes'] ?? 0);
        $out = ['factor' => $factor['ratio'], 'factor_sample' => $factor['sample'], 'min_similar' => self::MIN_SIMILAR, 'similar_count' => $scored->count()];

        if ($scored->count() >= self::MIN_SIMILAR) {
            $mins = $scored->pluck('task.actual_minutes')->map(fn ($m) => (int) $m)->sort()->values();
            $n = $mins->count();
            $median = $n % 2 ? $mins[intdiv($n, 2)] : ($mins[$n / 2 - 1] + $mins[$n / 2]) / 2;
            return $out + [
                'basis' => 'similar', 'suggested_minutes' => self::round5((int) round($median)),
                'range' => [(int) $mins->first(), (int) $mins->last()],
                'examples' => $scored->take(3)->map(fn ($x) => ['title' => $x['task']->title, 'actual_minutes' => (int) $x['task']->actual_minutes])->all(),
            ];
        }
        if ($current > 0 && $factor['ratio'] !== null && abs($factor['ratio'] - 1) >= 0.1) {
            return $out + ['basis' => 'factor', 'suggested_minutes' => self::round5((int) round($current * $factor['ratio'])), 'examples' => []];
        }
        return $out + ['basis' => null, 'suggested_minutes' => null, 'examples' => [],
            'reason' => $current > 0 && $factor['ratio'] !== null ? 'estimate_matches_history' : 'not_enough_history'];
    }

    /** Calibrated minutes for an AI or user estimate (unchanged without enough history). */
    public function calibrate(User $user, ?int $minutes): ?int
    {
        if (!$minutes) return $minutes;
        $r = $this->insights->planVsActual($user, 90)['estimation']['ratio'];
        return $r === null ? $minutes : self::round5((int) round($minutes * max(0.8, min(2.0, (float) $r))));
    }

    /** @return list<string> */
    public static function tokens(string $s): array
    {
        $s = mb_strtolower(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], $s));
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $s, $m);
        return array_values(array_unique(array_diff($m[0], self::STOP)));
    }

    private static function round5(int $m): int
    {
        return max(5, (int) (round($m / 5) * 5));
    }
}
