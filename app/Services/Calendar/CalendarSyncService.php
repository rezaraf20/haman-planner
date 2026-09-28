<?php
declare(strict_types=1);

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\ScheduleBlock;
use App\Models\Task;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Planner\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controlled, one-way synchronisation (no two-way merge, so no conflict resolution is needed):
 *   import  external busy events → calendar_events (they only block time; never become tasks)
 *   export  planner work blocks and scheduled tasks → the chosen external calendar
 * Failures are recorded on the connection and never break planning.
 */
final class CalendarSyncService
{
    public const IMPORT_PAST_DAYS = 7;
    public const WINDOW_DAYS = 60;

    public function __construct(private readonly SchedulingService $scheduling) {}

    /** @return array<string,CalendarProvider> */
    public function providers(): array
    {
        return ['google' => app(GoogleCalendarProvider::class)];
    }

    public function provider(string $key): ?CalendarProvider
    {
        return $this->providers()[$key] ?? null;
    }

    /** @return array{imported:int,exported:int,removed:int}|null null when skipped */
    public function sync(CalendarConnection $c): ?array
    {
        $provider = $this->provider($c->provider);
        $user = User::query()->find($c->user_id);
        if (!$provider || !$provider->isConfigured() || !$user || !$user->is_active || $c->status === 'revoked') {
            return null;
        }
        if (!app(Entitlements::class)->canUse($user, 'calendar')) {
            return null; // plan no longer includes calendar sync: keep data, stop syncing
        }
        $stats = ['imported' => 0, 'exported' => 0, 'removed' => 0];
        try {
            if ($c->import_enabled && $c->calendar_id) {
                $stats['imported'] = $this->import($provider, $c);
            }
            if ($c->export_enabled && $c->export_calendar_id) {
                [$stats['exported'], $stats['removed']] = $this->export($provider, $c, $user);
            }
            $c->forceFill(['status' => 'active', 'last_synced_at' => now(), 'last_error' => null])->save();
        } catch (CalendarAuthRevoked $e) {
            $c->forceFill(['status' => 'revoked', 'last_error' => 'revoked'])->save();
            Log::info('Calendar access revoked', ['connection_id' => $c->id, 'provider' => $c->provider]);
            return null;
        } catch (\Throwable $e) {
            $c->forceFill(['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 480)])->save();
            Log::warning('Calendar sync failed', ['connection_id' => $c->id, 'provider' => $c->provider, 'error' => $e->getMessage()]);
            return null;
        }
        return $stats;
    }

    public function syncAll(): int
    {
        $n = 0;
        CalendarConnection::withoutGlobalScopes()->whereIn('status', ['active', 'error'])->orderBy('id')
            ->chunkById(50, function ($chunk) use (&$n): void {
                foreach ($chunk as $c) {
                    if ($this->sync($c) !== null) $n++;
                }
            });
        return $n;
    }

    private function import(CalendarProvider $provider, CalendarConnection $c): int
    {
        $from = CarbonImmutable::now()->subDays(self::IMPORT_PAST_DAYS)->startOfDay();
        $to = CarbonImmutable::now()->addDays(self::WINDOW_DAYS)->endOfDay();
        $events = $provider->events($c, (string) $c->calendar_id, $from, $to);

        DB::transaction(function () use ($c, $events, $from, $to): void {
            $seen = [];
            foreach ($events as $e) {
                $seen[] = $e['id'];
                CalendarEvent::withoutGlobalScopes()->updateOrCreate(
                    ['calendar_connection_id' => $c->id, 'provider_event_id' => $e['id']],
                    ['user_id' => $c->user_id, 'title' => $e['title'], 'starts_at' => $e['starts_at'], 'ends_at' => $e['ends_at'], 'all_day' => $e['all_day'], 'is_busy' => $e['busy']],
                );
            }
            // Events removed (or moved out of the window) upstream disappear here too.
            CalendarEvent::withoutGlobalScopes()->where('calendar_connection_id', $c->id)
                ->where('starts_at', '<', \App\Support\LocalDate::db($to))->where('ends_at', '>', \App\Support\LocalDate::db($from))
                ->when($seen !== [], fn ($q) => $q->whereNotIn('provider_event_id', $seen))->delete();
        });
        return count($events);
    }

    /** @return array{0:int,1:int} exported, removed */
    private function export(CalendarProvider $provider, CalendarConnection $c, User $user): array
    {
        $from = CarbonImmutable::now()->subDay()->startOfDay();
        $to = CarbonImmutable::now()->addDays(self::WINDOW_DAYS)->endOfDay();
        $calendarId = (string) $c->export_calendar_id;
        $desired = [];
        foreach ($this->scheduling->items($user, $from, $to) as $item) {
            if (!in_array($item['kind'], ['task', 'focus'], true) || ($item['status'] ?? null) === 'cancelled') {
                continue;
            }
            $id = self::eventId($item['type'], (int) $item['id'], (int) $user->id);
            $desired[$id] = true;
            $provider->putEvent($c, $calendarId, $id, [
                'title' => $item['title'] ?: __('app.js.cal_kind_focus', [], $user->preferredLocale()),
                'starts_at' => CarbonImmutable::parse($item['starts_at']),
                'ends_at' => CarbonImmutable::parse($item['ends_at']),
                'description' => __('calendar.exported_description', [], $user->preferredLocale()),
            ]);
            if ($item['type'] === 'block') {
                ScheduleBlock::withoutGlobalScopes()->whereKey($item['id'])->update(['external_event_id' => $id, 'external_synced_at' => now()]);
            }
        }
        $removed = 0;
        foreach ($provider->exportedEventIds($c, $calendarId, $from, $to) as $existing) {
            if (!isset($desired[$existing])) {
                $provider->deleteEvent($c, $calendarId, $existing);
                $removed++;
            }
        }
        return [count($desired), $removed];
    }

    /** Deterministic Google-compatible id (base32hex: a–v, 0–9). */
    public static function eventId(string $type, int $id, int $userId): string
    {
        return 'hmn'.($type === 'block' ? 'b' : 't').$id.'u'.$userId;
    }

    /** Disconnect: best-effort revoke upstream, remove exported events if possible, delete local data. */
    public function disconnect(CalendarConnection $c): void
    {
        $provider = $this->provider($c->provider);
        if ($provider && $provider->isConfigured() && $c->status !== 'revoked') {
            try {
                if ($c->export_enabled && $c->export_calendar_id) {
                    $from = CarbonImmutable::now()->subDay();
                    foreach ($provider->exportedEventIds($c, (string) $c->export_calendar_id, $from, $from->addDays(self::WINDOW_DAYS + 1)) as $id) {
                        $provider->deleteEvent($c, (string) $c->export_calendar_id, $id);
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Could not remove exported calendar events on disconnect', ['connection_id' => $c->id, 'error' => $e->getMessage()]);
            }
            try {
                $provider->revoke($c);
            } catch (\Throwable) {
                // best effort
            }
        }
        DB::transaction(function () use ($c): void {
            CalendarEvent::withoutGlobalScopes()->where('calendar_connection_id', $c->id)->delete();
            ScheduleBlock::withoutGlobalScopes()->where('user_id', $c->user_id)->whereNotNull('external_event_id')->update(['external_event_id' => null, 'external_synced_at' => null]);
            $c->delete();
        });
    }

    /** Private iCalendar feed of planner blocks and scheduled tasks (read-only, token URL). */
    public function icsFeed(User $user): string
    {
        $from = CarbonImmutable::now()->subDays(30)->startOfDay();
        $to = CarbonImmutable::now()->addDays(90)->endOfDay();
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\r", "\n"], ['\\\\', '\\;', '\\,', '', '\\n'], $s);
        $fmt = fn (string $iso) => CarbonImmutable::parse($iso)->utc()->format('Ymd\THis\Z');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'haman-planner';
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Haman Planner//Planner feed//'.strtoupper($user->preferredLocale()), 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$esc((string) \App\Support\AppSettings::get('app_name'))];
        foreach ($this->scheduling->items($user, $from, $to) as $i) {
            if (!in_array($i['kind'], ['task', 'focus'], true) || ($i['status'] ?? null) === 'cancelled') continue;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.self::eventId($i['type'], (int) $i['id'], (int) $user->id).'@'.$host;
            $lines[] = 'DTSTAMP:'.CarbonImmutable::now()->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$fmt($i['starts_at']);
            $lines[] = 'DTEND:'.$fmt($i['ends_at']);
            $lines[] = 'SUMMARY:'.$esc((string) ($i['title'] ?: ''));
            if (($i['status'] ?? null) === 'completed') $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        // RFC 5545 line folding at 75 octets.
        $out = '';
        foreach ($lines as $line) {
            while (strlen($line) > 75) {
                $cut = 75;
                while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--; // don't split UTF-8
                $out .= substr($line, 0, $cut)."\r\n";
                $line = ' '.substr($line, $cut);
            }
            $out .= $line."\r\n";
        }
        return $out;
    }
}
