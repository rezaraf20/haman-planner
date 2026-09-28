<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\Analytics\ProductEvents;
use App\Services\Billing\Entitlements;
use App\Services\Calendar\CalendarException;
use App\Services\Calendar\CalendarSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Account → Calendar: connect Google Calendar (OAuth), choose calendars, sync, disconnect, iCal feed. */
final class CalendarController extends Controller
{
    public function __construct(private readonly CalendarSyncService $sync) {}

    private function back(): RedirectResponse
    {
        return redirect()->to(route('account.settings').'#calendar');
    }

    public static function redirectUri(string $provider): string
    {
        return (string) (config('services.google_calendar.redirect_uri') ?: route('calendar.callback', ['provider' => $provider]));
    }

    public function connect(Request $request, string $provider, Entitlements $entitlements): RedirectResponse
    {
        $entitlements->ensureFeature($request->user(), 'calendar');
        $p = $this->sync->provider($provider);
        abort_if($p === null, 404);
        if (!$p->isConfigured()) {
            return $this->back()->withErrors(['calendar' => __('calendar.not_configured')]);
        }
        $state = Str::random(40);
        $request->session()->put('calendar_oauth_state', [$provider, $state]);
        return redirect()->away($p->authorizationUrl($state, self::redirectUri($provider)));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $expected = $request->session()->pull('calendar_oauth_state');
        $p = $this->sync->provider($provider);
        if ($p === null || !is_array($expected) || $expected[0] !== $provider || !hash_equals((string) $expected[1], (string) $request->query('state'))) {
            return $this->back()->withErrors(['calendar' => __('calendar.state_mismatch')]);
        }
        if ($request->filled('error') || !$request->filled('code')) {
            return $this->back()->withErrors(['calendar' => __('calendar.denied')]);
        }
        try {
            $tokens = $p->exchangeCode((string) $request->query('code'), self::redirectUri($provider));
        } catch (CalendarException $e) {
            Log::warning('Calendar OAuth exchange failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            return $this->back()->withErrors(['calendar' => __('calendar.connect_failed')]);
        }
        $user = $request->user();
        $existing = CalendarConnection::query()->where('provider', $provider)->first();
        $connection = CalendarConnection::query()->updateOrCreate(['user_id' => $user->id, 'provider' => $provider], [
            'account_email' => $tokens['email'],
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?: $existing?->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'] - 60)),
            'status' => 'active',
            'last_error' => null,
            'calendar_id' => $existing?->calendar_id ?? 'primary',
            'calendar_name' => $existing?->calendar_name,
            'export_calendar_id' => $existing?->export_calendar_id ?? 'primary',
            'import_enabled' => $existing?->import_enabled ?? true,
            'export_enabled' => $existing?->export_enabled ?? false,
        ]);
        ProductEvents::record($user, ProductEvents::CALENDAR_CONNECTED, ['provider' => $provider], true);
        $this->sync->sync($connection);
        return $this->back()->with('status', __('calendar.connected', ['email' => $tokens['email'] ?? '']));
    }

    public function update(Request $request, CalendarConnection $connection): RedirectResponse
    {
        $data = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'export_calendar_id' => ['nullable', 'string', 'max:255'],
            'calendar_name' => ['nullable', 'string', 'max:255'],
        ]);
        $connection->update([
            'calendar_id' => $data['calendar_id'],
            'calendar_name' => $data['calendar_name'] ?? null,
            'export_calendar_id' => $data['export_calendar_id'] ?: $data['calendar_id'],
            'import_enabled' => $request->boolean('import_enabled'),
            'export_enabled' => $request->boolean('export_enabled'),
        ]);
        if (!$connection->import_enabled) {
            $connection->events()->delete();
        }
        $this->sync->sync($connection->refresh());
        return $this->back()->with('status', __('calendar.saved'));
    }

    public function syncNow(CalendarConnection $connection): RedirectResponse
    {
        $result = $this->sync->sync($connection);
        return $result === null
            ? $this->back()->withErrors(['calendar' => __('calendar.sync_failed')])
            : $this->back()->with('status', __('calendar.synced', ['imported' => $result['imported'], 'exported' => $result['exported']]));
    }

    public function disconnect(CalendarConnection $connection): RedirectResponse
    {
        $this->sync->disconnect($connection);
        return $this->back()->with('status', __('calendar.disconnected'));
    }

    // ----------------------------------------------------------------- private iCal feed

    public function createFeed(Request $request, Entitlements $entitlements): RedirectResponse
    {
        $entitlements->ensureFeature($request->user(), 'calendar');
        $plain = Str::random(48);
        $request->user()->forceFill(['calendar_feed_token' => hash('sha256', $plain)])->save();
        return $this->back()->with('calendar_feed_url', route('calendar.feed', ['token' => $plain]))->with('status', __('calendar.feed_created'));
    }

    public function deleteFeed(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['calendar_feed_token' => null])->save();
        return $this->back()->with('status', __('calendar.feed_deleted'));
    }

    public function feed(string $token, Entitlements $entitlements): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);
        $user = User::query()->where('calendar_feed_token', hash('sha256', $token))->where('is_active', true)->first();
        abort_if($user === null || !$entitlements->canUse($user, 'calendar'), 404);
        return response($this->sync->icsFeed($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="haman-planner.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
