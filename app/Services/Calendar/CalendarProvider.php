<?php
declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use Carbon\CarbonImmutable;

/**
 * An external calendar. Business logic (SchedulingService, CalendarSyncService) only talks to
 * this interface, so another provider (Microsoft, CalDAV…) can be added without touching it.
 */
interface CalendarProvider
{
    public function key(): string;

    /** Credentials present (e.g. OAuth client id/secret). */
    public function isConfigured(): bool;

    public function authorizationUrl(string $state, string $redirectUri): string;

    /** @return array{access_token:string,refresh_token:?string,expires_in:int,email:?string} */
    public function exchangeCode(string $code, string $redirectUri): array;

    /** Ensure a valid access token (refreshing if needed). Throws CalendarAuthRevoked when access is gone. */
    public function ensureToken(CalendarConnection $connection): void;

    /** @return list<array{id:string,name:string,primary:bool,writable:bool}> */
    public function calendars(CalendarConnection $connection): array;

    /**
     * Busy/free events in [from, to), excluding events this app exported itself.
     *
     * @return list<array{id:string,title:?string,starts_at:CarbonImmutable,ends_at:CarbonImmutable,all_day:bool,busy:bool}>
     */
    public function events(CalendarConnection $connection, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array;

    /** Create or update one exported planner item (idempotent, deterministic id). */
    public function putEvent(CalendarConnection $connection, string $calendarId, string $eventId, array $event): void;

    /** @return list<string> ids of events this app exported in [from, to) */
    public function exportedEventIds(CalendarConnection $connection, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array;

    public function deleteEvent(CalendarConnection $connection, string $calendarId, string $eventId): void;

    public function revoke(CalendarConnection $connection): void;
}
