<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Services\AI\AiBalanceService;
use App\Services\AI\ManagedAIProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin → AI: connections (keys encrypted), priority/fallback, budgets, balance and token usage. */
final class AdminAiController extends Controller
{
    public function index(Request $request): View
    {
        $monthStart = now()->startOfMonth();
        $providers = AiProvider::query()->orderBy('priority')->orderBy('id')->get();
        $month = AiUsage::query()->where('created_at', '>=', $monthStart);
        $byProvider = (clone $month)->selectRaw('ai_provider_id, provider, sum(prompt_tokens) as p, sum(completion_tokens) as c, sum(total_tokens) as t, count(*) as n, sum(case when success then 0 else 1 end) as f')
            ->groupBy('ai_provider_id', 'provider')->get();
        $cost = 0.0;
        $costKnown = false;
        foreach ($byProvider as $row) {
            $p = $providers->firstWhere('id', $row->ai_provider_id);
            $c = $p?->cost((int) $row->p, (int) $row->c);
            if ($c !== null) {
                $cost += $c;
                $costKnown = true;
            }
        }
        $totals = (clone $month)->selectRaw('coalesce(sum(prompt_tokens),0) as p, coalesce(sum(completion_tokens),0) as c, coalesce(sum(total_tokens),0) as t, count(*) as n, coalesce(sum(case when success then 0 else 1 end),0) as f')->first();
        $from = now()->subDays(13)->startOfDay();
        $daily = AiUsage::query()->where('created_at', '>=', $from)->selectRaw('DATE(created_at) as d, sum(total_tokens) as t')->groupBy('d')->pluck('t', 'd');
        $days = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $days[$d] = (int) ($daily[$d] ?? 0);
        }
        $features = (clone $month)->selectRaw('feature, sum(total_tokens) as t, count(*) as n')->groupBy('feature')->orderByDesc('t')->get();
        $topUsers = (clone $month)->whereNotNull('user_id')->selectRaw('user_id, sum(total_tokens) as t, count(*) as n')->groupBy('user_id')->orderByDesc('t')->limit(10)->get();
        $names = DB::table('users')->whereIn('id', $topUsers->pluck('user_id'))->pluck('name', 'id');
        $recentErrors = AiUsage::query()->where('success', false)->latest('id')->limit(8)->get(['provider', 'model', 'error', 'created_at']);

        return view('admin.ai', [
            'providers' => $providers, 'byProvider' => $byProvider->keyBy(fn ($r) => (string) ($r->ai_provider_id ?? 'env')),
            'totals' => $totals, 'cost' => $costKnown ? $cost : null, 'days' => $days, 'features' => $features,
            'topUsers' => $topUsers, 'names' => $names, 'recentErrors' => $recentErrors,
            'envConfigured' => filled(config('services.ai.api_key')),
            'envLabel' => (string) config('services.ai.provider', 'openai').' · '.(string) config('services.ai.model'),
            'edit' => $request->integer('edit') ? $providers->firstWhere('id', $request->integer('edit')) : null,
            'drivers' => AiProvider::DRIVERS,
        ]);
    }

    private function rules(?AiProvider $p): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'driver' => ['required', Rule::in(array_keys(AiProvider::DRIVERS))],
            'base_url' => ['nullable', 'url', 'max:255', Rule::requiredIf(fn () => request('driver') === 'custom')],
            'api_key' => [$p ? 'nullable' : 'required', 'string', 'max:500'],
            'model' => ['required', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:0'],
            'input_price_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'output_price_per_million' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    private function fill(AiProvider $p, Request $request, array $data): void
    {
        $p->fill([
            'name' => trim($data['name']), 'driver' => $data['driver'], 'base_url' => ($data['base_url'] ?? null) ?: null, 'model' => trim($data['model']),
            'priority' => (int) ($data['priority'] ?? 10), 'is_active' => $request->boolean('is_active'),
            'monthly_token_budget' => $data['monthly_token_budget'] ?? null,
            'input_price_per_million' => $data['input_price_per_million'] ?? null, 'output_price_per_million' => $data['output_price_per_million'] ?? null,
        ]);
        if (filled($data['api_key'] ?? null)) {
            $p->api_key = trim((string) $data['api_key']);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(null));
        $p = new AiProvider();
        $this->fill($p, $request, $data);
        $p->save();
        return redirect()->route('admin.ai')->with('status', __('admin.ai.saved'));
    }

    public function update(Request $request, AiProvider $provider): RedirectResponse
    {
        $data = $request->validate($this->rules($provider));
        $this->fill($provider, $request, $data);
        $provider->save();
        return redirect()->route('admin.ai')->with('status', __('admin.ai.saved'));
    }

    public function destroy(AiProvider $provider): RedirectResponse
    {
        $provider->delete();
        return redirect()->route('admin.ai')->with('status', __('admin.ai.deleted'));
    }

    public function toggle(AiProvider $provider): RedirectResponse
    {
        $provider->update(['is_active' => !$provider->is_active]);
        return back()->with('status', __('admin.ai.saved'));
    }

    /** Sends a tiny request through this connection only (recorded as feature "test"). */
    public function test(AiProvider $provider): RedirectResponse
    {
        $client = new ManagedAIProvider([['id' => $provider->id, 'label' => $provider->name, 'base' => $provider->baseUrl(), 'key' => (string) $provider->api_key, 'model' => $provider->model, 'provider' => null]]);
        try {
            $r = $client->chat([['role' => 'user', 'content' => 'Reply with the single word: ok']], ['max_tokens' => 5, '_feature' => 'test']);
            $provider->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
            return back()->with('status', __('admin.ai.test_ok', ['answer' => mb_substr(trim((string) ($r['choices'][0]['message']['content'] ?? '')), 0, 40), 'tokens' => (int) ($r['usage']['total_tokens'] ?? 0)]));
        } catch (\Throwable $e) {
            $provider->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
            return back()->withErrors(['ai' => __('admin.ai.test_failed', ['error' => mb_substr($e->getMessage(), 0, 160)])]);
        }
    }

    public function balance(AiProvider $provider, AiBalanceService $balances): RedirectResponse
    {
        $r = $balances->refresh($provider);
        return $r['ok'] ? back()->with('status', __('admin.ai.balance_updated')) : back()->withErrors(['ai' => __('admin.ai.balance_failed', ['error' => $r['error'] ?? ''])]);
    }
}
