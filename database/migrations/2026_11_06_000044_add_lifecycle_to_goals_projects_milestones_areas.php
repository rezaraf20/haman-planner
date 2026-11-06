<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completing and archiving goals, projects, milestones and areas.
 *
 *   completed_at — when the item was marked completed (goals, projects; milestones already have it)
 *   archived_at  — archived items leave the normal lists and live in the "Archive" tab
 *
 * Status stays the source of truth for completed/cancelled. Rows whose free-text status was
 * "archived" become archived (status → completed) so nothing is lost. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['goals', 'projects'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (!Schema::hasColumn($t, 'completed_at')) $table->timestamp('completed_at')->nullable();
                if (!Schema::hasColumn($t, 'archived_at')) $table->timestamp('archived_at')->nullable()->index();
            });
        }
        foreach (['milestones', 'areas'] as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (!Schema::hasColumn($t, 'archived_at')) $table->timestamp('archived_at')->nullable()->index();
            });
        }

        foreach (['goals', 'projects', 'milestones'] as $t) {
            DB::table($t)->whereRaw('lower(status) = ?', ['archived'])->update(['archived_at' => DB::raw('updated_at'), 'status' => 'completed']);
        }
        DB::table('areas')->whereRaw('lower(status) = ?', ['archived'])->update(['archived_at' => DB::raw('updated_at'), 'status' => 'active']);
        foreach (['goals', 'projects'] as $t) {
            DB::table($t)->where('status', 'completed')->whereNull('completed_at')->update(['completed_at' => DB::raw('updated_at')]);
        }
        DB::table('milestones')->where('status', 'completed')->whereNull('completed_at')->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        foreach (['goals', 'projects'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['completed_at', 'archived_at']));
        }
        foreach (['milestones', 'areas'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('archived_at'));
        }
    }
};
