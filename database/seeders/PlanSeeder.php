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
                'description' => ['fa' => 'همه‌ی امکانات برنامه‌ریزی، ربات تلگرام و سهمیه‌ی ماهانه‌ی AI.', 'en' => 'The full planner, the Telegram bot and a monthly AI allowance.'],
                'prices' => ['IRT' => ['monthly' => 0, 'yearly' => 0], 'USD' => ['monthly' => 0, 'yearly' => 0]],
                'limits' => ['ai_requests' => 30, 'active_goals' => null, 'active_projects' => null, 'open_tasks' => null],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => false],
                'trial_days' => 0, 'is_default' => true, 'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'name' => ['fa' => 'حرفه‌ای', 'en' => 'Pro'],
                'description' => ['fa' => 'برای کسی که هر روز با Planner و AI کار می‌کند.', 'en' => 'For people who plan with AI every day.'],
                'prices' => ['IRT' => ['monthly' => 190000, 'yearly' => 1900000], 'USD' => ['monthly' => 600, 'yearly' => 6000]],
                'limits' => ['ai_requests' => 500, 'active_goals' => null, 'active_projects' => null, 'open_tasks' => null],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => false],
                'trial_days' => 14, 'is_default' => false, 'sort_order' => 2,
            ],
            [
                'code' => 'business',
                'name' => ['fa' => 'کسب‌وکار', 'en' => 'Business'],
                'description' => ['fa' => 'بیشترین سهمیه‌ی AI و پشتیبانی در اولویت.', 'en' => 'The largest AI allowance and priority support.'],
                'prices' => ['IRT' => ['monthly' => 490000, 'yearly' => 4900000], 'USD' => ['monthly' => 1500, 'yearly' => 15000]],
                'limits' => ['ai_requests' => 3000, 'active_goals' => null, 'active_projects' => null, 'open_tasks' => null],
                'features' => ['telegram' => true, 'ai_planner' => true, 'advanced_analytics' => true, 'priority_support' => true],
                'trial_days' => 0, 'is_default' => false, 'sort_order' => 3,
            ],
        ];

        foreach ($plans as $data) {
            Plan::query()->firstOrCreate(['code' => $data['code']], $data + ['is_active' => true, 'is_public' => true]);
        }
    }
}
