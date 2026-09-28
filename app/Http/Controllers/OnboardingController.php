<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Entitlements;
use App\Services\Planner\PlannerService;
use App\Support\Locales;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Short first-run flow: profile → main goal → Telegram (optional) → AI intro. */
final class OnboardingController extends Controller
{
    public const STEPS = 4;

    public function show(Request $request, Entitlements $entitlements): View|RedirectResponse
    {
        $step = max(1, min(self::STEPS, (int) $request->query('step', 1)));
        if ($request->user()->onboarded_at === null) {
            ProductEvents::record($request->user(), ProductEvents::ONBOARDING_STARTED, [], true);
        }
        return view('onboarding.show', [
            'step' => $step,
            'user' => $request->user(),
            'timezones' => Timezones::options(),
            'aiLimit' => $entitlements->limit($request->user(), 'ai_requests'),
        ]);
    }

    public function store(Request $request, int $step, PlannerService $planner): RedirectResponse
    {
        $user = $request->user();
        if ($step === 1) {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'locale' => ['required', Rule::in(Locales::SUPPORTED)],
                'timezone' => ['required', 'timezone:all'],
            ]);
            $user->forceFill(['name' => trim($data['name']), 'locale' => $data['locale'], 'timezone' => $data['timezone']])->save();
            $request->session()->put('locale', $data['locale']);
        } elseif ($step === 2) {
            $data = $request->validate([
                'goal' => ['nullable', 'string', 'max:255'],
                'task' => ['nullable', 'string', 'max:255'],
            ]);
            $goal = filled($data['goal'] ?? null) ? Goal::create(['title' => trim($data['goal']), 'status' => 'active', 'importance' => 80, 'weight' => 1]) : null;
            if (filled($data['task'] ?? null)) {
                $planner->createTask(['title' => trim($data['task']), 'goal_id' => $goal?->id, 'status' => 'inbox']);
            }
        }

        if ($step >= self::STEPS) {
            return $this->finish($request);
        }
        return redirect()->route('onboarding', ['step' => $step + 1]);
    }

    public function skip(Request $request): RedirectResponse
    {
        return $this->finish($request);
    }

    private function finish(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->onboarded_at === null) {
            $user->forceFill(['onboarded_at' => now()])->save();
            ProductEvents::record($user, ProductEvents::ONBOARDING_COMPLETED, [], true);
        }
        return redirect()->route('planner.app')->with('status', __('onboarding.done'));
    }
}
