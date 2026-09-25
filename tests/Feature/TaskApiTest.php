<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.haman_planner.api_token' => 'test-token']);
        // The system API token acts as the planner owner (first active admin).
        $this->owner = \App\Models\User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-pass-123', 'is_admin' => true, 'is_active' => true]);
    }

    public function test_task_creation_accepts_schedule_and_focus_fields(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')->postJson('/api/tasks', [
            'title' => 'Plan release',
            'estimated_minutes' => 60,
            'planned_start' => '2026-09-19 10:00:00',
            'planned_end' => '2026-09-19 11:00:00',
            'energy_level' => 80,
            'focus_level' => 90,
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Plan release')
            ->assertJsonPath('energy_level', 80)
            ->assertJsonPath('focus_level', 90);

        $this->assertDatabaseHas('tasks', [
            'title' => 'Plan release',
            'estimated_minutes' => 60,
            'energy_level' => 80,
            'focus_level' => 90,
        ]);
    }

    public function test_task_creation_rejects_invalid_schedule_range(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer test-token')->postJson('/api/tasks', [
            'title' => 'Invalid schedule',
            'planned_start' => '2026-09-19 12:00:00',
            'planned_end' => '2026-09-19 11:00:00',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['planned_end']);
        $this->assertDatabaseMissing('tasks', ['title' => 'Invalid schedule']);
    }

    public function test_api_requires_token(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
    }

    public function test_completing_task_persists_completed_at(): void
    {
        $task = Task::create(['user_id' => $this->owner->id, 'title' => 'Complete me']);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->patchJson('/api/tasks/'.$task->id, ['status' => 'completed']);

        $response->assertOk()->assertJsonPath('progress', '100.00');
        $this->assertNotNull($task->fresh()->completed_at);
    }
}
