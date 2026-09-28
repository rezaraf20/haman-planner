<?php
declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Support\IntegrationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar over its REST API (no SDK). Scopes: read calendars/events (busy time) and
 * write events (export of planner blocks, only when the user enables it).
 * Exported events carry extendedProperties.private.haman = "1" and deterministic ids, so they
 * are recognisable, never imported back as busy time, and can be updated idempotently.
 */
final class GoogleCalendarProvider implements CalendarProvider
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const API = 'https://www.googleapis.com/calendar/v3';
    public const SCOPES = 'openid email https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';

    public function key(): string { return 'google'; }

    public function isConfigured(): bool
    {
        return filled(IntegrationSettings::get('google_client_id')) && filled(IntegrationSettings::get('google_client_secret'));
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTH.'?'.http_build_query([
            'client_id' => IntegrationSettings::get('google_client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $r = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'code' => $code,
            'client_id' => IntegrationSettings::get('google_client_id'),
            'client_secret' => IntegrationSettings::get('google_client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if ($r->failed() || !$r->json('access_token')) {
            throw new CalendarException('Google token exchange failed: '.($r->json('error') ?? $r->status()));
        }
        $email = null;
        $info = Http::withToken((string) $r->json('access_token'))->timeout(10)->get(self::USERINFO);
        if ($info->successful()) {
            $email = $info->json('email');
        }
        return [
            'access_token' => (string) $r->json('access_token'),
            'refresh_token' => $r->json('refresh_token'),
            'expires_in' => (int) ($r->json('expires_in') ?? 3600),
            'email' => is_string($email) ? $email : null,
        ];
    }

    public function ensureToken(CalendarConnection $c): void
    {
        if ($c->access_token && $c->token_expires_at && $c->token_expires_at->isAfter(now()->addMinute())) {
            return;
        }
        if (!$c->refresh_token) {
            throw new CalendarAuthRevoked('No refresh token; reconnect required.');
        }
        $r = Http::asForm()->timeout(15)->post(self::TOKEN, [
            'client_id' => IntegrationSettings::get('google_client_id'),
            'client_secret' => IntegrationSettings::get('google_client_secret'),
            'refresh_token' => $c->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if ($r->json('error') === 'invalid_grant' || $r->status() === 400 && $r->json('error') === 'invalid_grant') {
            throw new CalendarAuthRevoked('Access was revoked.');
        }
        if ($r->failed() || !$r->json('access_token')) {
            throw new CalendarException('Token refresh failed: HTTP '.$r->status());
        }
        $c->forceFill([
            'access_token' => (string) $r->json('access_token'),
            'token_expires_at' => now()->addSeconds(max(60, (int) ($r->json('expires_in') ?? 3600) - 60)),
        ])->save();
    }

    private function http(CalendarConnection $c): PendingRequest
    {
        $this->ensureToken($c);
        return Http::withToken((string) $c->access_token)->acceptJson()->timeout(20);
    }

    private function check(Response $r, string $what): Response
    {
        if ($r->status() === 401) {
            throw new CalendarAuthRevoked($what.': unauthorized');
        }
        if ($r->failed()) {
            throw new CalendarException($what.' failed: HTTP '.$r->status().' '.substr((string) ($r->json('error.message') ?? ''), 0, 160));
        }
        return $r;
    }

    public function calendars(CalendarConnection $c): array
    {
        $r = $this->check($this->http($c)->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'reader', 'maxResults' => 100]), 'calendarList');
        return collect($r->json('items') ?? [])->map(fn ($i) => [
            'id' => (string) $i['id'],
            'name' => (string) ($i['summaryOverride'] ?? $i['summary'] ?? $i['id']),
            'primary' => (bool) ($i['primary'] ?? false),
            'writable' => in_array($i['accessRole'] ?? '', ['owner', 'writer'], true),
        ])->values()->all();
    }

    public function events(CalendarConnection $c, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $page = null;
        $guard = 0;
        do {
            $r = $this->check($this->http($c)->get(self::API.'/calendars/'.rawurlencode($calendarId).'/events', array_filter([
                'timeMin' => $from->toRfc3339String(), 'timeMax' => $to->toRfc3339String(), 'singleEvents' => 'true',
                'orderBy' => 'startTime', 'maxResults' => 250, 'showDeleted' => 'false', 'pageToken' => $page,
            ])), 'events.list');
            foreach ($r->json('items') ?? [] as $e) {
                if (($e['status'] ?? '') === 'cancelled' || ($e['extendedProperties']['private']['haman'] ?? null) === '1') {
                    continue; // deleted, or one of our own exported blocks
                }
                $declined = collect($e['attendees'] ?? [])->contains(fn ($a) => ($a['self'] ?? false) && ($a['responseStatus'] ?? '') === 'declined');
                $allDay = isset($e['start']['date']);
                $start = $allDay ? CarbonImmutable::parse($e['start']['date'], $c->user?->preferredTimezone()) : CarbonImmutable::parse($e['start']['dateTime'] ?? 'now');
                $end = $allDay ? CarbonImmutable::parse($e['end']['date'] ?? $e['start']['date'], $c->user?->preferredTimezone()) : CarbonImmutable::parse($e['end']['dateTime'] ?? $e['start']['dateTime'] ?? 'now');
                $out[] = [
                    'id' => (string) $e['id'],
                    'title' => isset($e['summary']) ? mb_substr((string) $e['summary'], 0, 250) : null,
                    'starts_at' => $start, 'ends_at' => $end->gt($start) ? $end : $start->addMinutes(15), 'all_day' => $allDay,
                    // All-day events and "free" (transparent) or declined events don't block time.
                    'busy' => !$allDay && !$declined && ($e['transparency'] ?? 'opaque') !== 'transparent',
                ];
            }
            $page = $r->json('nextPageToken');
        } while ($page && ++$guard < 20);
        return $out;
    }

    public function putEvent(CalendarConnection $c, string $calendarId, string $eventId, array $event): void
    {
        $body = [
            'id' => $eventId,
            'summary' => mb_substr((string) $event['title'], 0, 250),
            'description' => $event['description'] ?? null,
            'start' => ['dateTime' => $event['starts_at']->toRfc3339String()],
            'end' => ['dateTime' => $event['ends_at']->toRfc3339String()],
            'transparency' => 'opaque',
            'status' => 'confirmed',
            'extendedProperties' => ['private' => ['haman' => '1']],
            'reminders' => ['useDefault' => false],
        ];
        $base = self::API.'/calendars/'.rawurlencode($calendarId).'/events';
        $r = $this->http($c)->put($base.'/'.rawurlencode($eventId), $body);
        if ($r->status() === 404) {
            $r = $this->http($c)->post($base, $body);
        }
        $this->check($r, 'events.put');
    }

    public function exportedEventIds(CalendarConnection $c, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $r = $this->check($this->http($c)->get(self::API.'/calendars/'.rawurlencode($calendarId).'/events', [
            'timeMin' => $from->toRfc3339String(), 'timeMax' => $to->toRfc3339String(), 'privateExtendedProperty' => 'haman=1',
            'singleEvents' => 'true', 'maxResults' => 2500, 'showDeleted' => 'false',
        ]), 'events.list(exported)');
        return collect($r->json('items') ?? [])->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
    }

    public function deleteEvent(CalendarConnection $c, string $calendarId, string $eventId): void
    {
        $r = $this->http($c)->delete(self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));
        if (!in_array($r->status(), [404, 410], true)) {
            $this->check($r, 'events.delete');
        }
    }

    public function revoke(CalendarConnection $c): void
    {
        $token = $c->refresh_token ?: $c->access_token;
        if ($token) {
            Http::asForm()->timeout(10)->post(self::REVOKE, ['token' => $token]); // best effort
        }
    }
}
