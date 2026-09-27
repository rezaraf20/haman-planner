<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: per-user language, timezone, preferences and onboarding state.
 * Existing users keep Persian + Asia/Tehran and are marked as already onboarded,
 * so nothing changes for them after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 5)->default('fa');
            }
            if (!Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable();
            }
            if (!Schema::hasColumn('users', 'preferences')) {
                $table->json('preferences')->nullable();
            }
            if (!Schema::hasColumn('users', 'onboarded_at')) {
                $table->timestamp('onboarded_at')->nullable();
            }
        });

        DB::table('users')->whereNull('timezone')->update(['timezone' => 'Asia/Tehran']);
        DB::table('users')->whereNull('onboarded_at')->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['locale', 'timezone', 'preferences', 'onboarded_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
