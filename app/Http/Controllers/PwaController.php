<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AppSettings;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Web app manifest (localized) and the offline fallback page. */
final class PwaController extends Controller
{
    public function manifest(): JsonResponse
    {
        $name = (string) AppSettings::get('app_name');
        $loc = app()->getLocale();
        return response()->json([
            'id' => '/planner',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => (string) AppSettings::get('tagline'),
            'lang' => $loc,
            'dir' => Locales::dir($loc),
            'start_url' => '/planner?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f4f6f9',
            'theme_color' => '#172033',
            'categories' => ['productivity'],
            'icons' => [
                ['src' => asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => asset('icons/maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('app.nav.today'), 'url' => '/planner'],
                ['name' => __('app.nav.calendar'), 'url' => '/planner#calendar'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function offline(): View
    {
        return view('pwa.offline');
    }
}
