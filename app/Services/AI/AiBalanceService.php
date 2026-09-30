<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Remaining account credit, for providers whose API reports it:
 *  - OpenRouter: GET /api/v1/credits → total_credits − total_usage (USD)
 *  - DeepSeek:   GET /user/balance   → balance_infos[].total_balance (per currency)
 * Other providers (OpenAI, Gemini, Groq, xAI) do not expose a balance API for normal keys;
 * for them the admin sets a monthly token budget and the app tracks usage itself.
 */
final class AiBalanceService
{
    /** @return array{ok:bool,amount?:float,currency?:string,detail?:string,error?:string} */
    public function refresh(AiProvider $p): array
    {
        $result = match ($p->driver) {
            'openrouter' => $this->openRouter($p),
            'deepseek' => $this->deepSeek($p),
            default => ['ok' => false, 'error' => 'unsupported'],
        };
        if ($result['ok']) {
            $p->forceFill(['balance' => $result, 'balance_checked_at' => now()])->save();
        }
        return $result;
    }

    private function openRouter(AiProvider $p): array
    {
        $r = Http::timeout(15)->withToken((string) $p->api_key)->acceptJson()->get('https://openrouter.ai/api/v1/credits');
        if ($r->failed()) {
            return ['ok' => false, 'error' => 'HTTP '.$r->status()];
        }
        $credits = (float) $r->json('data.total_credits', 0);
        $used = (float) $r->json('data.total_usage', 0);
        return ['ok' => true, 'amount' => round($credits - $used, 4), 'currency' => 'USD', 'detail' => 'credits '.$credits.' / used '.round($used, 4)];
    }

    private function deepSeek(AiProvider $p): array
    {
        $base = preg_replace('#/v1$#', '', $p->baseUrl()) ?: 'https://api.deepseek.com';
        $r = Http::timeout(15)->withToken((string) $p->api_key)->acceptJson()->get($base.'/user/balance');
        if ($r->failed()) {
            return ['ok' => false, 'error' => 'HTTP '.$r->status()];
        }
        $info = (array) ($r->json('balance_infos.0') ?? []);
        if ($info === []) {
            return ['ok' => false, 'error' => 'no balance in response'];
        }
        return ['ok' => true, 'amount' => (float) ($info['total_balance'] ?? 0), 'currency' => (string) ($info['currency'] ?? ''), 'detail' => ($r->json('is_available') ? 'available' : 'not available')];
    }
}
