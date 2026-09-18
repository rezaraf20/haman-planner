<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reminder;
use App\Services\Planner\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_without_chat_id_is_failed_without_delivery(): void
    {
        $reminder = Reminder::create([
            'scheduled_at' => now()->subMinute(),
            'status' => 'pending',
            'payload' => ['message' => 'Test'],
        ]);

        $sent = app(ReminderService::class)->dispatch($reminder);

        $this->assertFalse($sent);
        $this->assertSame('failed', $reminder->fresh()->status);
    }

    public function test_stale_processing_reminders_are_released(): void
    {
        $reminder = Reminder::create([
            'scheduled_at' => now()->subMinute(),
            'status' => 'processing',
            'payload' => ['chat_id' => '123'],
        ]);

        $reminder->updated_at = now()->subMinutes(11);
        $reminder->save();

        $due = app(ReminderService::class)->due();

        $this->assertTrue($due->contains('id', $reminder->id));
        $this->assertSame('pending', $reminder->fresh()->status);
    }
}
