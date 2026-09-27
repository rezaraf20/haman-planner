<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: composite indexes for the per-user queries every screen now runs. */
return new class extends Migration
{
    private const INDEXES = [
        'tasks' => [['user_id', 'status'], ['user_id', 'deadline'], ['user_id', 'planned_start']],
        'schedule_blocks' => [['user_id', 'starts_at']],
        'execution_logs' => [['user_id', 'started_at']],
        'activity_logs' => [['user_id', 'created_at']],
        'ai_interactions' => [['user_id', 'created_at']],
        'reminders' => [['user_id', 'status']],
    ];

    private function name(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_index';
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            foreach ($sets as $columns) {
                if (Schema::hasIndex($table, $this->name($table, $columns))) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $this->name($table, $columns))) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
                }
            }
        }
    }
};
