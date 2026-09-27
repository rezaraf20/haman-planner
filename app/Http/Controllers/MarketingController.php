<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\BillingService;
use App\Support\LocaleUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Public, indexable marketing pages. The locale comes from the URL (/ = fa, /en = en). */
final class MarketingController extends Controller
{
    private function remember(Request $request): void
    {
        \App\Support\LandingContent::apply(); // admin-edited texts on top of the built-in ones

        // Guests who browse /en keep English on /login and /register too.
        if (!$request->user() && $request->hasSession()) {
            $request->session()->put('locale', app()->getLocale());
        }
    }

    private function plans()
    {
        return Plan::query()->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->get();
    }

    public function home(Request $request, BillingService $billing): View|RedirectResponse
    {
        if ($request->user() && !$request->boolean('preview')) {
            return redirect()->route('planner.app');
        }
        $this->remember($request);
        return view('marketing.home', ['plans' => $this->plans(), 'currency' => $billing->currencyFor(app()->getLocale()), 'interval' => 'monthly']);
    }

    public function pricing(Request $request, BillingService $billing): View
    {
        $this->remember($request);
        $interval = $request->query('interval') === 'yearly' ? 'yearly' : 'monthly';
        return view('marketing.pricing', ['plans' => $this->plans(), 'currency' => $billing->currencyFor(app()->getLocale()), 'interval' => $interval]);
    }

    public function privacy(Request $request): View
    {
        $this->remember($request);
        return view('marketing.legal', ['page' => 'privacy']);
    }

    public function terms(Request $request): View
    {
        $this->remember($request);
        return view('marketing.legal', ['page' => 'terms']);
    }

    public function sitemap(): Response
    {
        $urls = [];
        foreach (LocaleUrls::MARKETING as $fa => $en) {
            $urls[] = ['fa' => route($fa), 'en' => route($en)];
        }
        return response()->view('marketing.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /$',
            'Allow: /en',
            'Allow: /pricing',
            'Allow: /privacy',
            'Allow: /terms',
            'Disallow: /planner', 'Disallow: /settings', 'Disallow: /billing', 'Disallow: /support', 'Disallow: /admin',
            'Disallow: /onboarding', 'Disallow: /api/', 'Disallow: /login', 'Disallow: /register', 'Disallow: /forgot-password', 'Disallow: /reset-password',
            '',
            'Sitemap: '.route('marketing.sitemap'),
        ];
        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
