<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Review;
use App\Services\Planner\ReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_generates_deterministic_actions_from_metrics(): void
    {
        $review = app(ReviewService::class)->generate(
            'weekly',
            Carbon::parse('2026-09-14'),
            Carbon::parse('2026-09-20'),
            [
                'tasks_completed' => 4,
                'completion_rate' => 40,
                'execution_minutes' => 240,
                'estimation_error_percent' => 35,
                'overdue_open_tasks' => 3,
                'tasks_with_failures' => 2,
                'average_schedule_variance_minutes' => 45,
            ]
        );

        $this->assertInstanceOf(Review::class, $review);
        $this->assertCount(5, $review->actions_json);
        $this->assertSame('high', $review->actions_json[0]['priority']);
        $this->assertDatabaseHas('reviews', ['type' => 'weekly']);
    }
}
