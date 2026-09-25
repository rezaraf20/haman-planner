<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds nullable planner ownership (user_id) to user-owned planner tables.
 *
 * Production-safe and additive:
 *  1. add nullable user_id columns (no rows touched),
 *  2. backfill existing rows to the first active user (if any user exists),
 *  3. only then add indexes and foreign keys (nullOnDelete).
 * No rows are deleted. failure_reasons stays a global lookup table.
 */
return new class extends Migration
{
    private const TABLES = [
        'areas', 'goals', 'projects', 'milestones', 'tasks', 'notes', 'decisions',
        'reminders', 'daily_plans', 'schedule_blocks', 'execution_logs', 'task_dependencies',
        'reviews', 'ai_interactions', 'pending_actions', 'activity_logs',
    ];

    public function up(): void
    {
        // 1. Nullable column first.
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('user_id')->nullable();
            });
        }

        // 2. Backfill existing rows to the first active user. Never fails when there are no users.
        $ownerId = DB::table('users')->where('is_active', true)->orderBy('id')->value('id');
        if ($ownerId !== null) {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                    DB::table($table)->whereNull('user_id')->update(['user_id' => $ownerId]);
                }
            }
        }

        // 3. Indexes + foreign keys (equivalent to foreignId()->nullable()->constrained('users')->nullOnDelete()).
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            $index = $table.'_user_id_index';
            $foreign = $table.'_user_id_foreign';
            $hasIndex = Schema::hasIndex($table, $index);
            $hasForeign = collect(Schema::getForeignKeys($table))->contains(fn (array $fk): bool => ($fk['name'] ?? null) === $foreign);
            Schema::table($table, function (Blueprint $blueprint) use ($hasIndex, $hasForeign): void {
                if (!$hasIndex) {
                    $blueprint->index('user_id');
                }
                if (!$hasForeign) {
                    $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                }
            });
        }

        // Daily plans were globally unique per date; make them unique per owner and date instead.
        // The old unique guarantees no duplicates, so the composite unique is always satisfiable.
        if (Schema::hasIndex('daily_plans', 'daily_plans_plan_date_unique')) {
            Schema::table('daily_plans', function (Blueprint $blueprint): void {
                $blueprint->dropUnique('daily_plans_plan_date_unique');
            });
        }
        if (!Schema::hasIndex('daily_plans', 'daily_plans_user_id_plan_date_unique')) {
            Schema::table('daily_plans', function (Blueprint $blueprint): void {
                $blueprint->unique(['user_id', 'plan_date']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('daily_plans', 'daily_plans_user_id_plan_date_unique')) {
            Schema::table('daily_plans', function (Blueprint $blueprint): void {
                $blueprint->dropUnique('daily_plans_user_id_plan_date_unique');
            });
        }
        // Restore the global unique only when it cannot fail (no duplicate dates across users).
        $duplicates = DB::table('daily_plans')->select('plan_date')->groupBy('plan_date')->havingRaw('count(*) > 1')->exists();
        if (!$duplicates && !Schema::hasIndex('daily_plans', 'daily_plans_plan_date_unique')) {
            Schema::table('daily_plans', function (Blueprint $blueprint): void {
                $blueprint->unique('plan_date');
            });
        }

        foreach (array_reverse(self::TABLES) as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            $foreign = $table.'_user_id_foreign';
            $hasForeign = collect(Schema::getForeignKeys($table))->contains(fn (array $fk): bool => ($fk['name'] ?? null) === $foreign);
            $hasIndex = Schema::hasIndex($table, $table.'_user_id_index');
            Schema::table($table, function (Blueprint $blueprint) use ($hasForeign, $hasIndex): void {
                if ($hasForeign) {
                    $blueprint->dropForeign(['user_id']);
                }
                if ($hasIndex) {
                    $blueprint->dropIndex(['user_id']);
                }
                $blueprint->dropColumn('user_id');
            });
        }
    }
};
