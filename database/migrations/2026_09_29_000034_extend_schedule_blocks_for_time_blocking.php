<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Time blocking: block kind, fixed/flexible, a label, and the link to an exported calendar event. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_blocks', function (Blueprint $table): void {
            if (!Schema::hasColumn('schedule_blocks', 'kind')) $table->string('kind', 20)->default('task'); // task | focus | break | buffer
            if (!Schema::hasColumn('schedule_blocks', 'is_fixed')) $table->boolean('is_fixed')->default(false);
            if (!Schema::hasColumn('schedule_blocks', 'title')) $table->string('title')->nullable();
            if (!Schema::hasColumn('schedule_blocks', 'external_event_id')) $table->string('external_event_id')->nullable();
            if (!Schema::hasColumn('schedule_blocks', 'external_synced_at')) $table->timestamp('external_synced_at')->nullable();
        });
        if (!Schema::hasIndex('schedule_blocks', 'schedule_blocks_user_id_starts_at_index')) {
            Schema::table('schedule_blocks', fn (Blueprint $t) => $t->index(['user_id', 'starts_at']));
        }
        if (!Schema::hasIndex('tasks', 'tasks_user_id_planned_start_index')) {
            Schema::table('tasks', fn (Blueprint $t) => $t->index(['user_id', 'planned_start']));
        }
    }

    public function down(): void
    {
        foreach (['schedule_blocks' => 'schedule_blocks_user_id_starts_at_index', 'tasks' => 'tasks_user_id_planned_start_index'] as $table => $index) {
            if (Schema::hasIndex($table, $index)) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($index));
        }
        Schema::table('schedule_blocks', function (Blueprint $table): void {
            foreach (['kind', 'is_fixed', 'title', 'external_event_id', 'external_synced_at'] as $c) {
                if (Schema::hasColumn('schedule_blocks', $c)) $table->dropColumn($c);
            }
        });
    }
};
