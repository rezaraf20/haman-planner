<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\LifecycleMailer;
use App\Services\Planner\WeeklyReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Hourly: weekly review emails (opt-in, on the first day of the user's week, after 08:00 local
 * time) and a single "come back" reminder after 7 days of inactivity (opt-out). Dedupe keys make
 * every run safe to repeat.
 */
final class SendLifecycleEmails extends Command
{
    protected $signature = 'planner:lifecycle-emails';
    protected $description = 'Send weekly review and inactivity lifecycle emails (deduplicated).';

    public function handle(LifecycleMailer $mailer, WeeklyReviewService $reviews): int
    {
        $weekly = 0;
        $inactive = 0;
        User::query()->where('is_active', true)->whereNotNull('onboarded_at')->lazyById(200)->each(function (User $user) use ($mailer, $reviews, &$weekly, &$inactive): void {
            try {
                $weekly += $this->weekly($user, $mailer, $reviews) ? 1 : 0;
                $inactive += $this->inactive($user, $mailer) ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        });
        $this->info("Queued {$weekly} weekly review and {$inactive} inactivity email(s).");
        return self::SUCCESS;
    }

    private function weekly(User $user, LifecycleMailer $mailer, WeeklyReviewService $reviews): bool
    {
        if (!$user->preference('email_weekly_review')) {
            return false;
        }
        $local = CarbonImmutable::now($user->preferredTimezone());
        $thisWeek = $reviews->weekStart($user, $local);
        if (!$local->isSameDay($thisWeek) || $local->hour < 8) {
            return false;
        }
        $start = $thisWeek->subWeek();
        $dedupe = 'weekly-'.$start->toDateString();
        if (\DB::table('notification_deliveries')->where('user_id', $user->id)->where('dedupe_key', $dedupe)->exists()) {
            return false;
        }
        // Deterministic metrics only: an email must never silently spend the user's AI quota.
        $review = \App\Models\Review::query()->ownedBy($user->id)->where('type', 'weekly')->where('period_start', $start->toDateString())->first()
            ?? $reviews->generate($user, $start, false);
        $data = $reviews->present($review, $user);
        return $mailer->send($user, 'weekly_review', [
            'summary' => (string) ($data['ai_summary'] ?: $data['summary']),
            'lines' => array_slice((array) $data['recommendations_text'], 0, 3),
        ], $dedupe);
    }

    private function inactive(User $user, LifecycleMailer $mailer): bool
    {
        $seen = $user->last_seen_at;
        // Only people who used the product and then stopped for a week — never long-gone accounts.
        if ($seen === null || $seen->gt(now()->subDays(7)) || $seen->lt(now()->subDays(30))) {
            return false;
        }
        return $mailer->send($user, 'inactive_reminder', [], 'inactive-'.$seen->toDateString());
    }
}
