<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Existing plans get the new feature flags switched ON (nothing a user can do today is taken away;
 * admins can restrict them later in Admin → Plans) and an attachment storage allowance:
 * 100 MB on free plans, unlimited on paid plans. Only missing keys are added.
 */
return new class extends Migration
{
    private const FEATURES = ['recurring_tasks', 'calendar', 'advanced_ai_planning', 'attachments'];

    public function up(): void
    {
        if (!Schema::hasTable('plans')) return;
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            $limits = json_decode((string) $plan->limits, true) ?: [];
            foreach (self::FEATURES as $f) {
                $features[$f] ??= true;
            }
            if (!array_key_exists('attachment_storage_mb', $limits)) {
                $free = true;
                foreach ((array) (json_decode((string) $plan->prices, true) ?: []) as $intervals) {
                    foreach ((array) $intervals as $v) {
                        if ((int) $v > 0) $free = false;
                    }
                }
                $limits['attachment_storage_mb'] = $free ? 100 : null;
            }
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features), 'limits' => json_encode($limits)]);
        }
    }

    public function down(): void
    {
        // Data-only and harmless to keep; nothing to undo.
    }
};
