<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Creates the initial plan catalogue only if a plan code does not exist yet, so prices,
 * limits and features edited in Admin → Plans are never overwritten on deploy.
 * Prices are placeholders to be reviewed by the owner before enabling payments.
 * Amounts are in minor units: USD cents, IRT tomans.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'free',
                'name' => ['fa' => 'رایگان', 'en' => 'Free'],
                'description' => ['fa' => 'برنامه‌ریزی روزانه، ربات تلگرام و طعم هامان AI — برای شروع.', 'en' => 'Daily planning, the Telegram bot and a taste of Haman AI — to get started.'],
                'prices' => ['IRT' => ['monthly' => 0, 'yearly' => 0], 'USD' => ['monthly' => 0, 'yearly' => 0]],
                'limits' => ['ai_requests' => 15, 'active_goals' => 3, 'active_projects' => 3, 'open_tasks' => null, 'active_recurring' => 3, 'attachment_storage_mb' => 20],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => false, 'priority_support' => false, 'recurring_tasks' => true, 'calendar' => false, 'advanced_ai_planning' => false, 'attachments' => true],
                'trial_days' => 0, 'is_default' => true, 'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'name' => ['fa' => 'حرفه‌ای', 'en' => 'Pro'],
                'description' => ['fa' => 'هامان AI هفته‌ات را می‌چیند، با Google Calendar همگام می‌شود و کل تاریخچه‌ات را تحلیل می‌کند.', 'en' => 'Haman AI plans your week, syncs with Google Calendar and analyses your whole history.'],
                'prices' => ['IRT' => ['monthly' => 190000, 'yearly' => 1900000], 'USD' => ['monthly' => 600, 'yearly' => 6000]],
                'limits' => ['ai_requests' => 500, 'active_goals' => null, 'active_projects' => null, 'open_tasks' => null, 'active_recurring' => null, 'attachment_storage_mb' => 2048],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => false, 'recurring_tasks' => true, 'calendar' => true, 'advanced_ai_planning' => true, 'attachments' => true],
                'trial_days' => 7, 'is_default' => false, 'sort_order' => 2,
            ],
            [
                'code' => 'business',
                'name' => ['fa' => 'کسب‌وکار', 'en' => 'Business'],
                'description' => ['fa' => 'بیشترین سهمیه‌ی AI و پشتیبانی در اولویت.', 'en' => 'The largest AI allowance and priority support.'],
                'prices' => ['IRT' => ['monthly' => 490000, 'yearly' => 4900000], 'USD' => ['monthly' => 1500, 'yearly' => 15000]],
                'limits' => ['ai_requests' => 3000, 'active_goals' => null, 'active_projects' => null, 'open_tasks' => null, 'active_recurring' => null, 'attachment_storage_mb' => 10240],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => true, 'recurring_tasks' => true, 'calendar' => true, 'advanced_ai_planning' => true, 'attachments' => true],
                'trial_days' => 0, 'is_default' => false, 'sort_order' => 3,
            ],
        ];

        foreach ($plans as $data) {
            Plan::query()->firstOrCreate(['code' => $data['code']], $data + ['is_active' => true, 'is_public' => true]);
        }
    }
}
