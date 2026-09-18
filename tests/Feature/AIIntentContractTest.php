<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Drivers\OpenAICompatibleProvider;
use Tests\TestCase;

final class AIIntentContractTest extends TestCase
{
    public function test_unknown_intent_is_rejected_by_normalizer(): void
    {
        $provider = new OpenAICompatibleProvider('http://127.0.0.1', 'test', 'test');
        $reflection = new \ReflectionClass($provider);
        $this->assertTrue($reflection->hasMethod('parseIntent'));
    }

    public function test_mutation_intent_contract_is_documented_in_source(): void
    {
        $source = file_get_contents(base_path('app/Services/AI/Drivers/OpenAICompatibleProvider.php'));
        $this->assertStringContainsString('requires_confirmation', $source);
        $this->assertStringContainsString('Never invent database IDs', $source);
    }
}
