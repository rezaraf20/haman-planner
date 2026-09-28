<?php
declare(strict_types=1);

namespace App\Services\Account;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Analytics\ProductEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-subject controls: export everything a user owns, and delete an account.
 *
 * What deletion does:
 *  - planner data (tasks, goals, projects, notes, decisions, reminders, plans, logs, AI history,
 *    pending actions, activity) owned by the user            → deleted
 *  - support tickets opened by the user (and their messages)   → deleted
 *  - Telegram link and bot conversation state                  → removed with the account
 *  - subscriptions, usage counters, API tokens                 → deleted (FK cascade)
 *  - payments and invoices                                     → kept as accounting records; the
 *    user reference is cleared and, when configured, personal fields are pseudonymised
 *  - product analytics events                                  → kept without the user reference
 * Shared/system data (failure reason catalogue, plans, settings) is never touched.
 */
final class AccountDataService
{
    /** Planner tables with a user_id column, children first. */
    public const PLANNER_TABLES = [
        'task_dependencies', 'execution_logs', 'schedule_blocks', 'reminders', 'notes', 'tasks', 'recurring_tasks', 'milestones',
        'projects', 'goals', 'decisions', 'areas', 'daily_plans', 'reviews', 'ai_interactions', 'pending_actions', 'activity_logs',
    ];

    /** @return array<string,mixed> */
    public function export(User $user): array
    {
        $out = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only(['id', 'name', 'email', 'locale', 'timezone', 'telegram_username', 'telegram_linked_at', 'created_at', 'last_seen_at']),
            'preferences' => $user->allPreferences(),
        ];
        foreach (self::PLANNER_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $out['planner'][$table] = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get()
                    ->map(fn ($row) => collect((array) $row)->except(['user_id', 'input_hash'])->all())->all();
            }
        }
        $out['subscriptions'] = $user->subscriptions()->with('plan:id,code')->get()
            ->map(fn ($s) => ['plan' => $s->plan?->code] + $s->only(['status', 'billing_interval', 'trial_ends_at', 'current_period_start', 'current_period_end', 'canceled_at']))->all();
        $out['invoices'] = Invoice::query()->where('user_id', $user->id)->get(['number', 'amount', 'currency', 'issued_at', 'lines'])->toArray();
        $out['attachments'] = \App\Models\Attachment::withoutGlobalScopes()->where('user_id', $user->id)->get(['attachable_type', 'attachable_id', 'original_name', 'mime', 'size', 'created_at'])->toArray();
        $out['support_tickets'] = SupportTicket::query()->where('user_id', $user->id)->with('messages:id,support_ticket_id,is_staff,body,created_at')->get(['id', 'subject', 'status', 'created_at'])->toArray();
        return $out;
    }

    public function delete(User $user): void
    {
        $chatId = $user->telegram_chat_id;
        $email = $user->email;
        $anonymize = (bool) config('billing.anonymize_invoices_on_account_deletion', true);
        $pseudonym = 'deleted-user-'.substr(hash('sha256', $user->id.'|'.$email.'|'.config('app.key')), 0, 12);

        app(\App\Services\Planner\AttachmentService::class)->purge(null, null, (int) $user->id);
        foreach (\App\Models\CalendarConnection::withoutGlobalScopes()->where('user_id', $user->id)->get() as $connection) {
            try {
                app(\App\Services\Calendar\CalendarSyncService::class)->disconnect($connection); // revokes access upstream
            } catch (\Throwable $e) {
                report($e);
            }
        }
        DB::transaction(function () use ($user, $email, $anonymize, $pseudonym): void {
            foreach (self::PLANNER_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                    DB::table($table)->where('user_id', $user->id)->delete();
                }
            }
            SupportTicket::query()->where('user_id', $user->id)->each(fn (SupportTicket $t) => $t->delete());

            if ($anonymize) {
                Invoice::query()->where('user_id', $user->id)->update(['billing_name' => $pseudonym, 'billing_email' => null]);
                Payment::query()->where('user_id', $user->id)->update(['customer_email' => null]);
            }
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $user->delete(); // cascades subscriptions, usage counters, API tokens; nulls payments/invoices/events
        });

        if ($chatId !== null) {
            Cache::forget('telegram:planner:state:'.$chatId);
        }
        ProductEvents::record(null, ProductEvents::ACCOUNT_DELETED);
    }
}
