<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TelegramVoiceValidationTest extends TestCase
{
    public function test_webhook_rejects_invalid_secret_before_processing_voice(): void
    {
        config(['app.env' => 'production', 'services.telegram.webhook_secret' => 'secret']);
        Http::fake();

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => [
                'chat' => ['id' => 1],
                'voice' => ['file_id' => 'voice-file'],
            ],
        ]);

        $response->assertUnauthorized();
        Http::assertNothingSent();
    }
}
