<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring tasks: a series (template + rule) in `recurring_tasks`; occurrences are ordinary
 * tasks linked by recurring_task_id + occurrence_date and generated lazily (never far ahead).
 * Additive only: existing tasks are untouched (the new columns are nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('recurring_tasks')) {
            Schema::create('recurring_tasks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority')->default('p2');
                $table->unsignedTinyInteger('importance')->default(50);
                $table->decimal('weight', 8, 2)->default(1);
                $table->unsignedInteger('estimated_minutes')->default(0);
                // Rule
                $table->string('frequency');                 // daily | weekly | monthly | yearly
                $table->string('calendar', 10)->default('gregorian'); // gregorian | jalali (monthly/yearly day & month)
                $table->unsignedSmallInteger('interval')->default(1);
                $table->json('by_weekday')->nullable();      // ISO days 1 (Mon) … 7 (Sun), weekly only
                $table->unsignedTinyInteger('by_month_day')->nullable(); // monthly/yearly (clamped to month end)
                $table->unsignedTinyInteger('by_month')->nullable();     // yearly
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->unsignedInteger('max_occurrences')->nullable();
                $table->string('time_of_day', 5)->nullable(); // HH:MM in `timezone`
                $table->string('timezone', 64);
                $table->string('status')->default('active');  // active | stopped
                $table->date('generated_until')->nullable();  // generation cursor (never regenerates earlier dates)
                $table->unsignedInteger('occurrences_generated')->default(0);
                $table->timestamp('stopped_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'generated_until']);
            });
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (!Schema::hasColumn('tasks', 'recurring_task_id')) {
                $table->foreignId('recurring_task_id')->nullable()->constrained('recurring_tasks')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'occurrence_date')) {
                $table->date('occurrence_date')->nullable();
            }
            if (!Schema::hasColumn('tasks', 'recurrence_exception')) {
                $table->string('recurrence_exception', 20)->nullable(); // skipped | edited | moved
            }
        });
        if (!Schema::hasIndex('tasks', 'tasks_recurring_task_id_occurrence_date_unique')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->unique(['recurring_task_id', 'occurrence_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasIndex('tasks', 'tasks_recurring_task_id_occurrence_date_unique')) {
                $table->dropUnique(['recurring_task_id', 'occurrence_date']);
            }
            if (Schema::hasColumn('tasks', 'recurring_task_id')) {
                $table->dropConstrainedForeignId('recurring_task_id');
            }
            foreach (['occurrence_date', 'recurrence_exception'] as $c) {
                if (Schema::hasColumn('tasks', $c)) $table->dropColumn($c);
            }
        });
        Schema::dropIfExists('recurring_tasks');
    }
};
