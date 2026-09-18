<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Drivers\OpenAICompatibleProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AIParserTest extends TestCase
{
    public function test_parser_normalizes_intent_and_arguments(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"intent":"create_task","confidence":0.91,"arguments":{"title":"Write report"},"requires_confirmation":false}'],
                ]],
            ], 200),
        ]);

        $provider = new OpenAICompatibleProvider('https://ai.test/v1', 'key', 'model');
        $result = $provider->parseIntent('create a task');

        $this->assertSame('CREATE_TASK', $result['intent']);
        $this->assertSame('Write report', $result['arguments']['title']);
        $this->assertSame(0.91, $result['confidence']);
        $this->assertTrue($result['requires_confirmation']);
    }

    public function test_invalid_ai_json_becomes_unknown(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'not json']]],
            ], 200),
        ]);

        $provider = new OpenAICompatibleProvider('https://ai.test/v1', 'key', 'model');
        $result = $provider->parseIntent('anything');

        $this->assertSame('UNKNOWN', $result['intent']);
        $this->assertSame([], $result['arguments']);
    }
}
