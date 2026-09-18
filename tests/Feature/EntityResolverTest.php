<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use App\Services\AI\EntityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EntityResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_tasks_are_returned_as_candidates(): void
    {
        Task::create(['title' => 'Prepare Haman report']);
        Task::create(['title' => 'Prepare Haman report today']);

        $result = app(EntityResolver::class)->resolveWithStatus('task', ['title' => 'Prepare Haman report']);

        $this->assertSame('resolved', $result['status']);
    }

    public function test_missing_entity_is_reported_without_guessing(): void
    {
        $result = app(EntityResolver::class)->resolveWithStatus('task', ['title' => 'Does not exist']);

        $this->assertSame('not_found', $result['status']);
    }
}
