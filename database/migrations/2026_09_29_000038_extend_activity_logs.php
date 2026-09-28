<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Activity timeline: where the change came from (web, api, telegram, ai, system). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            if (!Schema::hasColumn('activity_logs', 'channel')) $table->string('channel', 20)->nullable();
        });
        if (!Schema::hasIndex('activity_logs', 'activity_logs_user_id_created_at_index')) {
            Schema::table('activity_logs', fn (Blueprint $t) => $t->index(['user_id', 'created_at']));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('activity_logs', 'activity_logs_user_id_created_at_index')) {
            Schema::table('activity_logs', fn (Blueprint $t) => $t->dropIndex('activity_logs_user_id_created_at_index'));
        }
        Schema::table('activity_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('activity_logs', 'channel')) $table->dropColumn('channel');
        });
    }
};
