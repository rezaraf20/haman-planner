<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReminderRetryTest extends TestCase
{
 use RefreshDatabase;
 public function test_retry_fields_exist_with_safe_defaults(): void
 {
  $reminder=Reminder::create(['type'=>'telegram','scheduled_at'=>now(),'status'=>'pending','payload'=>['chat_id'=>'1']]);
  $this->assertSame(0,(int)$reminder->attempts);
  $this->assertSame(3,(int)$reminder->max_attempts);
  $this->assertNull($reminder->next_attempt_at);
 }
}
