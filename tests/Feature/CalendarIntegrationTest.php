<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AppSetting;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\Plan;
use App\Models\ProductEvent;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'UTC'));
        config(['services.google_calendar.client_id' => 'cid.apps.googleusercontent.com', 'services.google_calendar.client_secret' => 'GOCSPX-secret']);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now(), 'locale' => 'en', 'timezone' => 'UTC',
            'preferences' => ['work_days' => [1, 2, 3, 4, 5], 'work_start' => '09:00', 'work_end' => '17:00']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Mutable fake Google state (Http::fake stubs are registered once; later calls would not override them). */
    private array $g = ['events' => [], 'token' => null, 'eventsStatus' => 200, 'faked' => false];

    private function fakeGoogle(array $events = [], ?array $tokenError = null, int $eventsStatus = 200): void
    {
        $this->g['events'] = $events;
        $this->g['token'] = $tokenError;
        $this->g['eventsStatus'] = $eventsStatus;
        if ($this->g['faked']) {
            return;
        }
        $this->g['faked'] = true;
        Http::fake([
            'https://oauth2.googleapis.com/token' => fn () => $this->g['token'] ? Http::response($this->g['token'], 400)
                : Http::response(['access_token' => 'ya29.access', 'refresh_token' => '1//refresh', 'expires_in' => 3600]),
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'u@gmail.com']),
            'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'u@gmail.com', 'summary' => 'Me', 'primary' => true, 'accessRole' => 'owner'],
                ['id' => 'holidays', 'summary' => 'Holidays', 'accessRole' => 'reader'],
            ]]),
            'https://www.googleapis.com/calendar/v3/calendars/*/events?*privateExtendedProperty*' => Http::response(['items' => [['id' => 'hmnb999u1']]]),
            'https://www.googleapis.com/calendar/v3/calendars/*/events?*' => fn () => $this->g['eventsStatus'] === 200
                ? Http::response(['items' => $this->g['events']]) : Http::response(['error' => ['message' => 'Backend Error']], $this->g['eventsStatus']),
            'https://www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'x']),
        ]);
    }

    private function connect(): CalendarConnection
    {
        $this->actingAs($this->user);
        $redirect = $this->get('/settings/calendar/google/connect')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $redirect);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('calendar.events', $q['scope']);
        $this->get('/settings/calendar/google/callback?code=abc&state='.$q['state'])->assertRedirect(route('account.settings').'#calendar');
        return CalendarConnection::withoutGlobalScopes()->where('user_id', $this->user->id)->firstOrFail();
    }

    public function test_oauth_connect_stores_encrypted_tokens_and_imports_busy_time(): void
    {
        $this->fakeGoogle([
            ['id' => 'm1', 'summary' => 'Client meeting', 'start' => ['dateTime' => '2026-10-05T10:00:00Z'], 'end' => ['dateTime' => '2026-10-05T11:00:00Z']],
            ['id' => 'free', 'summary' => 'Reminder', 'transparency' => 'transparent', 'start' => ['dateTime' => '2026-10-05T12:00:00Z'], 'end' => ['dateTime' => '2026-10-05T12:30:00Z']],
            ['id' => 'mine', 'summary' => 'Exported block', 'extendedProperties' => ['private' => ['haman' => '1']], 'start' => ['dateTime' => '2026-10-05T13:00:00Z'], 'end' => ['dateTime' => '2026-10-05T14:00:00Z']],
            ['id' => 'allday', 'summary' => 'Holiday', 'start' => ['date' => '2026-10-06'], 'end' => ['date' => '2026-10-07']],
        ]);
        $c = $this->connect();

        $this->assertSame(['u@gmail.com', 'active', 'primary'], [$c->account_email, $c->status, $c->calendar_id]);
        $raw = DB::table('calendar_connections')->where('id', $c->id)->first();
        $this->assertStringNotContainsString('ya29.access', (string) $raw->access_token);
        $this->assertStringNotContainsString('1//refresh', (string) $raw->refresh_token);
        $this->assertSame('ya29.access', $c->access_token);
        $this->assertArrayNotHasKey('access_token', $c->toArray());

        $events = CalendarEvent::withoutGlobalScopes()->orderBy('provider_event_id')->get();
        $this->assertSame(['allday', 'free', 'm1'], $events->pluck('provider_event_id')->all(), 'our own exported events are never imported');
        $this->assertSame([false, false, true], $events->pluck('is_busy')->all());
        $this->assertTrue(ProductEvent::where('event', 'calendar_connected')->exists());

        // The busy meeting reduces available time and shows as a conflict when placing a task there.
        $plain = str_repeat('c', 48);
        ApiToken::create(['user_id' => $this->user->id, 'token_hash' => hash('sha256', $plain), 'name' => 't', 'token_prefix' => 'cccc']);
        $day = $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/schedule?from=2026-10-05&to=2026-10-05')->json('days.0');
        $this->assertSame(420, $day['available_minutes']);
        $t = Task::create(['user_id' => $this->user->id, 'title' => 'Write']);
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->postJson('/api/schedule/place', ['task_id' => $t->id, 'starts_at' => '2026-10-05T10:30:00Z'])
            ->assertStatus(409)->assertJsonPath('conflicts.0.title', 'Client meeting');

        // Events removed upstream disappear on the next sync.
        $this->fakeGoogle([]);
        app(CalendarSyncService::class)->sync($c->fresh());
        $this->assertSame(0, CalendarEvent::withoutGlobalScopes()->count());
    }

    public function test_state_is_verified_and_denied_consent_is_handled(): void
    {
        $this->fakeGoogle();
        $this->actingAs($this->user)->get('/settings/calendar/google/connect');
        $this->get('/settings/calendar/google/callback?code=abc&state=forged')->assertSessionHasErrors('calendar');
        $this->get('/settings/calendar/google/connect');
        $state = session('calendar_oauth_state')[1];
        $this->get('/settings/calendar/google/callback?error=access_denied&state='.$state)->assertSessionHasErrors('calendar');
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_export_is_idempotent_and_removes_stale_events(): void
    {
        $this->fakeGoogle();
        $c = $this->connect();
        $t = Task::create(['user_id' => $this->user->id, 'title' => 'Focus on API', 'planned_start' => '2026-10-05 13:00:00', 'planned_end' => '2026-10-05 15:00:00']);
        $block = ScheduleBlock::create(['user_id' => $this->user->id, 'kind' => 'focus', 'title' => 'Deep work', 'starts_at' => now()->addHours(4), 'ends_at' => now()->addHours(6)]);
        $this->post(route('calendar.update', $c), ['calendar_id' => 'u@gmail.com', 'export_calendar_id' => 'u@gmail.com', 'import_enabled' => '1', 'export_enabled' => '1'])->assertRedirect();

        $puts = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'PUT')->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values()->all();
        $this->assertContains('hmnb'.$block->id.'u'.$this->user->id, $puts);
        $this->assertContains('hmnt'.$t->id.'u'.$this->user->id, $puts);
        $this->assertSame('hmnb'.$block->id.'u'.$this->user->id, $block->fresh()->external_event_id);
        $deleted = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'DELETE')->map(fn ($p) => basename(parse_url($p[0]->url(), PHP_URL_PATH)))->values()->all();
        $this->assertContains('hmnb999u1', $deleted, 'stale exported event removed');
        $this->assertNotContains('hmnb'.$block->id.'u'.$this->user->id, $deleted);
        $this->assertNotContains('hmnt'.$t->id.'u'.$this->user->id, $deleted);
        $body = collect(Http::recorded())->first(fn ($p) => $p[0]->method() === 'PUT')[0]->data();
        $this->assertSame('1', $body['extendedProperties']['private']['haman']);
    }

    public function test_expired_token_is_refreshed_and_revoked_access_degrades_gracefully(): void
    {
        $this->fakeGoogle();
        $c = $this->connect();
        $c->forceFill(['token_expires_at' => now()->subMinute()])->save();
        app(CalendarSyncService::class)->sync($c->fresh());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['grant_type'] === 'refresh_token');

        $c->fresh()->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->fakeGoogle([], ['error' => 'invalid_grant']);
        $this->assertNull(app(CalendarSyncService::class)->sync($c->fresh()));
        $this->assertSame('revoked', $c->fresh()->status);
        $this->actingAs($this->user)->get('/settings')->assertOk()->assertSee(__('calendar.reconnect', [], 'en'));

        // Google errors are recorded; planning keeps working.
        $c->fresh()->forceFill(['status' => 'active', 'token_expires_at' => now()->addHour()])->save();
        $this->fakeGoogle([], null, 503);
        $this->assertNull(app(CalendarSyncService::class)->sync($c->fresh()));
        $this->assertSame('error', $c->fresh()->status);
        $this->assertStringContainsString('503', (string) $c->fresh()->last_error);
    }

    public function test_disconnect_revokes_and_removes_local_data(): void
    {
        $this->fakeGoogle([['id' => 'm1', 'summary' => 'Meeting', 'start' => ['dateTime' => '2026-10-05T10:00:00Z'], 'end' => ['dateTime' => '2026-10-05T11:00:00Z']]]);
        $c = $this->connect();
        $this->assertSame(1, CalendarEvent::withoutGlobalScopes()->count());
        $this->post(route('calendar.disconnect', $c))->assertRedirect();
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());
        $this->assertSame(0, CalendarEvent::withoutGlobalScopes()->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_connections_are_private_and_gated_by_plan(): void
    {
        $this->fakeGoogle();
        $c = $this->connect();
        $other = User::create(['name' => 'O', 'email' => 'o@example.com', 'password' => 'secret123', 'is_active' => true, 'onboarded_at' => now()]);
        $this->actingAs($other)->post(route('calendar.update', $c), ['calendar_id' => 'x'])->assertNotFound();
        $this->post(route('calendar.sync', $c))->assertNotFound();
        $this->post(route('calendar.disconnect', $c))->assertNotFound();
        $this->assertSame(1, CalendarConnection::withoutGlobalScopes()->count());

        $this->seed(PlanSeeder::class);
        $free = Plan::where('code', 'free')->first();
        $free->update(['features' => array_merge($free->features, ['calendar' => false])]);
        $this->actingAs($other)->get('/settings/calendar/google/connect')->assertRedirect()->assertSessionHasErrors('plan');
        $this->assertNull(app(CalendarSyncService::class)->sync($c->fresh()), 'sync stops when the plan no longer includes it');
    }

    public function test_not_configured_shows_a_message_instead_of_failing(): void
    {
        config(['services.google_calendar.client_id' => null, 'services.google_calendar.client_secret' => null]);
        $this->actingAs($this->user)->get('/settings/calendar/google/connect')->assertRedirect()->assertSessionHasErrors('calendar');
        $this->get('/settings')->assertOk()->assertSee(__('calendar.not_configured', [], 'en'));
    }

    public function test_private_ical_feed(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'Board meeting prep, part 1', 'planned_start' => '2026-10-06 09:00:00', 'planned_end' => '2026-10-06 10:00:00']);
        $this->actingAs($this->user)->post(route('calendar.feed.create'))->assertRedirect();
        $url = session('calendar_feed_url');
        $this->assertNotEmpty($url);
        auth()->logout();

        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:Board meeting prep\, part 1', $ics);
        $this->assertStringContainsString("\r\n", $ics);
        $this->get('/calendar/feed/'.str_repeat('A', 48).'.ics')->assertNotFound();

        $this->actingAs($this->user)->post(route('calendar.feed.create'));
        auth()->logout();
        $this->get(parse_url($url, PHP_URL_PATH))->assertNotFound(); // regenerated: the old link is dead
    }

    public function test_admin_can_store_google_credentials_encrypted(): void
    {
        config(['services.google_calendar.client_id' => null, 'services.google_calendar.client_secret' => null]);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'is_active' => true, 'is_admin' => true, 'onboarded_at' => now()]);
        $this->actingAs($this->user)->get('/admin/integrations')->assertForbidden();
        $this->actingAs($admin)->get('/admin/integrations')->assertOk()->assertSee('/settings/calendar/google/callback');
        $this->post('/admin/integrations', ['google_client_id' => 'abc.apps.googleusercontent.com', 'google_client_secret' => 'GOCSPX-verysecret'])->assertRedirect();
        $this->assertStringNotContainsString('GOCSPX-verysecret', (string) AppSetting::find('int_google_client_secret')->value);
        $this->assertTrue(app(CalendarSyncService::class)->provider('google')->isConfigured());
        $this->assertStringNotContainsString('GOCSPX-verysecret', $this->get('/admin/integrations')->getContent());
    }
}
