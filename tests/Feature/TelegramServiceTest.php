<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class TelegramServiceTest extends TestCase
{
    private function sentMarkup(): ?array
    {
        $request = Http::recorded()->last()[0];
        $data = $request->data();
        return isset($data['reply_markup']) ? json_decode($data['reply_markup'], true)['inline_keyboard'] : null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token']);
    }

    public function test_keyboard_is_normalized_to_rows(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $svc = app(TelegramService::class);
        $a = ['text' => 'A', 'callback_data' => 'a'];
        $b = ['text' => 'B', 'callback_data' => 'b'];

        $svc->sendMessage(1, 'x', [$a, $b]);                 // flat row
        $this->assertSame([[$a, $b]], $this->sentMarkup());

        $svc->sendMessage(1, 'x', [[$a], $b, [], ['bad']]);  // mixed rows, bare button, empty + invalid rows
        $this->assertSame([[$a], [$b]], $this->sentMarkup());

        $svc->editMessage(1, 5, 'x', []);                    // empty keyboard -> no reply_markup
        $this->assertNull($this->sentMarkup());
    }

    public function test_ok_false_response_throws(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 200)]);
        $this->expectException(RuntimeException::class);
        app(TelegramService::class)->sendMessage(1, 'x');
    }
}
