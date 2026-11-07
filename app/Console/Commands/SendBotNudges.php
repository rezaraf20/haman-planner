<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FocusSession;
use App\Models\User;
use App\Services\Planner\RitualService;
use App\Services\Telegram\TelegramService;
use App\Support\PlannerUserContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram messages that need a clock:
 *  - "time is up" for focus sessions started from the bot (client_uuid starting with FOCUS_UUID_PREFIX), once per session;
 *  - morning / evening ritual reminders, once per day each, only for users who turned them on
 *    (preference ritual_bot_nudges, off by default) and only when the Today page would offer them.
 */
final class SendBotNudges extends Command
{
    protected $signature = 'planner:bot-nudges';
    protected $description = 'Send focus timer and ritual reminder messages to Telegram.';

    public function handle(TelegramService $telegram, RitualService $rituals): int
    {
        $sent = 0;

        $sessions = FocusSession::withoutGlobalScopes()->where('status', 'running')->whereRaw('CAST(client_uuid AS TEXT) LIKE ?', [\App\Services\Telegram\TelegramPlannerBotService::FOCUS_UUID_PREFIX.'-%'])
            ->where('started_at', '>=', now()->subHours(12))->with(['task' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'title')])->get();
        foreach ($sessions as $s) {
            if ($s->remaining_seconds > 0) continue;
            $user = User::find($s->user_id);
            if (!$user || !$user->is_active || !$user->telegram_chat_id) continue;
            if (!Cache::add('bot-nudge:focus:'.$s->id, 1, now()->addDay())) continue;
            $sent += $this->send($telegram, $user, __('botx.fc.time_up', ['time' => $s->task ? ' — '.$s->task->title : ''], $user->preferredLocale()),
                [[['text' => '✅ '.__('botx.fc.finish', [], $user->preferredLocale()), 'callback_data' => 'fcf'], ['text' => '⏱ '.__('botx.btn.focus', [], $user->preferredLocale()), 'callback_data' => 'fc']]]);
        }

        $users = User::query()->where('is_active', true)->whereNotNull('telegram_chat_id')->get();
        foreach ($users as $user) {
            if ($user->preference('ritual_bot_nudges') !== true || $user->preference('rituals_enabled') === false) continue;
            $kind = PlannerUserContext::runAs((int) $user->id, fn () => $rituals->prompt($user));
            if (!$kind) continue;
            $day = $rituals->today($user)->toDateString();
            if (!Cache::add("bot-nudge:ritual:{$user->id}:{$day}:{$kind}", 1, now()->addHours(26))) continue;
            $loc = $user->preferredLocale();
            $sent += $this->send($telegram, $user, __('botx.rit.nudge_'.$kind, [], $loc), [[
                ['text' => ($kind === 'morning' ? '🌅 ' : '🌙 ').__('botx.rit.start_'.$kind, [], $loc), 'callback_data' => $kind === 'morning' ? 'rm' : 're'],
                ['text' => __('rituals.skip_today', [], $loc), 'callback_data' => 'rsk:'.$kind],
            ]]);
        }

        $this->info("Sent {$sent} message(s).");
        return self::SUCCESS;
    }

    private function send(TelegramService $telegram, User $user, string $text, array $keyboard): int
    {
        try {
            $telegram->sendMessage((string) $user->telegram_chat_id, $text, $keyboard);
            return 1;
        } catch (\Throwable $e) {
            report($e); // never let one chat stop the others
            return 0;
        }
    }
}
