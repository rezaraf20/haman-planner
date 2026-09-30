<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan catalogue v2 — give paid plans a real reason to exist (requested by the owner).
 *
 * Plans are recognised by their data, not their codes: a plan whose prices are all zero is a
 * free plan; any other plan is paid. Nothing is deleted and no user's data is touched — limits
 * only stop NEW goals/projects/recurring series/uploads beyond the allowance.
 *
 *   free plans: 15 AI requests/month, 3 active goals, 3 active projects, 3 active recurring
 *               tasks, 20 MB attachments; no Google Calendar sync, no AI week planning /
 *               AI weekly review, analytics limited to the last 7 days.
 *   paid plans: every feature on, recurring tasks unlimited.
 *   the first paid public plan: 7-day free trial.
 *
 * Everything stays editable afterwards in Admin → Plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans')) return;
        $firstPaidTrialSet = false;
        foreach (DB::table('plans')->orderBy('sort_order')->orderBy('id')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            $limits = json_decode((string) $plan->limits, true) ?: [];
            $update = [];

            if ($this->isFree($plan)) {
                $limits = array_merge($limits, [
                    'ai_requests' => 15, 'active_goals' => 3, 'active_projects' => 3,
                    'active_recurring' => 3, 'attachment_storage_mb' => 20,
                ]);
                $features = array_merge($features, [
                    'telegram' => true, 'ai_planner' => true, 'recurring_tasks' => true, 'attachments' => true,
                    'calendar' => false, 'advanced_ai_planning' => false, 'advanced_analytics' => false,
                ]);
                $update['description'] = json_encode([
                    'fa' => 'برنامه‌ریزی روزانه، ربات تلگرام و طعم هامان AI — برای شروع.',
                    'en' => 'Daily planning, the Telegram bot and a taste of Haman AI — to get started.',
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $limits['active_recurring'] = null;
                foreach (['telegram', 'ai_planner', 'recurring_tasks', 'attachments', 'calendar', 'advanced_ai_planning', 'advanced_analytics'] as $f) {
                    $features[$f] = true;
                }
                if (!$firstPaidTrialSet && (bool) $plan->is_public && (bool) $plan->is_active) {
                    $update['trial_days'] = 7;
                    $update['description'] = json_encode([
                        'fa' => 'هامان AI هفته‌ات را می‌چیند، با Google Calendar همگام می‌شود و کل تاریخچه‌ات را تحلیل می‌کند.',
                        'en' => 'Haman AI plans your week, syncs with Google Calendar and analyses your whole history.',
                    ], JSON_UNESCAPED_UNICODE);
                    $firstPaidTrialSet = true;
                }
            }
            $update['features'] = json_encode($features);
            $update['limits'] = json_encode($limits);
            DB::table('plans')->where('id', $plan->id)->update($update);
        }
    }

    private function isFree(object $plan): bool
    {
        foreach ((array) (json_decode((string) $plan->prices, true) ?: []) as $intervals) {
            foreach ((array) $intervals as $v) {
                if ((int) $v > 0) return false;
            }
        }
        return true;
    }

    public function down(): void
    {
        // Data-only; plans can be changed back in Admin → Plans.
    }
};
