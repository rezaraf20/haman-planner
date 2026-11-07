<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Focus Mode and Habit Tracking. Additive only.
 *
 * focus_sessions — one row per focus timer. Time spent on a task is written once, through the
 *                  existing execution-log path (execution_log_id), so there is a single source of
 *                  truth for "actual time". client_uuid makes start idempotent (retries/double taps).
 * habits         — behaviours to build or reduce; independent of recurring tasks.
 * habit_entries  — at most one entry per habit per local day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('planned_minutes');
            $t->string('status', 20)->default('running'); // running | paused | completed | cancelled | interrupted
            $t->timestamp('started_at');
            $t->timestamp('paused_at')->nullable();
            $t->unsignedInteger('paused_seconds')->default(0);
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('actual_seconds')->nullable();
            $t->foreignId('execution_log_id')->nullable()->constrained()->nullOnDelete();
            $t->text('note')->nullable();
            $t->uuid('client_uuid')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'started_at']);
            $t->index(['user_id', 'status']);
            $t->unique(['user_id', 'client_uuid']);
        });

        Schema::create('habits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('icon', 16)->nullable();
            $t->string('color', 20)->nullable();
            $t->string('kind', 10)->default('build');      // build | reduce
            $t->string('measure', 10)->default('binary');  // binary | numeric
            $t->decimal('target_value', 10, 2)->nullable(); // numeric: goal (build) or limit (reduce)
            $t->string('unit', 30)->nullable();
            $t->string('frequency', 12)->default('daily'); // daily | weekdays | weekly | interval
            $t->json('weekdays')->nullable();               // ISO 1..7 for "weekdays"
            $t->unsignedTinyInteger('times_per_week')->nullable(); // for "weekly"
            $t->unsignedSmallInteger('interval_days')->nullable(); // for "interval"
            $t->date('start_date');
            $t->string('status', 10)->default('active');   // active | paused
            $t->json('pauses')->nullable();                 // [{from: Y-m-d, to: Y-m-d|null}] — paused days are never "missed"
            $t->timestamp('archived_at')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'archived_at', 'status']);
        });

        Schema::create('habit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $t->date('entry_date');
            $t->boolean('completed')->default(false);
            $t->decimal('value', 10, 2)->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['habit_id', 'entry_date']);
            $t->index(['user_id', 'entry_date']);
        });

        // Plan allowance for habits: free plans 3 active habits, paid plans unlimited. Never overwrite
        // a value an admin already set; plans are recognised by price, not code.
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->get() as $plan) {
                $limits = json_decode((string) $plan->limits, true) ?: [];
                if (array_key_exists('active_habits', $limits)) continue;
                $free = true;
                foreach ((array) (json_decode((string) $plan->prices, true) ?: []) as $intervals) {
                    foreach ((array) $intervals as $v) if ((int) $v > 0) $free = false;
                }
                $limits['active_habits'] = $free ? 3 : null;
                DB::table('plans')->where('id', $plan->id)->update(['limits' => json_encode($limits)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('habit_entries');
        Schema::dropIfExists('habits');
        Schema::dropIfExists('focus_sessions');
    }
};
