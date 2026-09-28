<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_deliveries: one row per lifecycle message sent (dedupe + audit, no content stored).
 * user_activity_days: one row per user per active day (DAU/WAU/MAU and retention without tracking pages).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 50);
                $table->string('dedupe_key', 120);
                $table->string('channel', 20)->default('mail');
                $table->timestamp('sent_at');
                $table->unique(['user_id', 'dedupe_key']);
                $table->index(['type', 'sent_at']);
            });
        }
        if (!Schema::hasTable('user_activity_days')) {
            Schema::create('user_activity_days', function (Blueprint $table): void {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('day');
                $table->primary(['user_id', 'day']);
                $table->index('day');
            });
        }
        Schema::table('reviews', function (Blueprint $table): void {
            if (!Schema::hasColumn('reviews', 'ai_summary')) $table->text('ai_summary')->nullable();
        });
        Schema::table('ai_interactions', function (Blueprint $table): void {
            if (!Schema::hasColumn('ai_interactions', 'request_id')) $table->string('request_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table): void {
            if (Schema::hasColumn('ai_interactions', 'request_id')) $table->dropColumn('request_id');
        });
        Schema::table('reviews', function (Blueprint $table): void {
            if (Schema::hasColumn('reviews', 'ai_summary')) $table->dropColumn('ai_summary');
        });
        Schema::dropIfExists('user_activity_days');
        Schema::dropIfExists('notification_deliveries');
    }
};
