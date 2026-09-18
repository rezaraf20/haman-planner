<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class TelegramWebhookTest extends TestCase
{
    public function test_production_webhook_requires_secret(): void
    {
        config(['app.env' => 'production', 'services.telegram.webhook_secret' => 'secret']);

        $response = $this->postJson('/api/telegram/webhook', [
            'message' => ['chat' => ['id' => 1], 'text' => '/today'],
        ]);

        $response->assertUnauthorized();
    }
}
