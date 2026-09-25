<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: platform settings, user activity timestamp and support tickets.
 * No existing table or row is modified except adding a nullable users.last_seen_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_settings')) {
            Schema::create('app_settings', function (Blueprint $table): void {
                $table->string('key', 100)->primary();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->index();
            });
        }

        if (!Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject');
                $table->string('status', 20)->default('open'); // open | answered | closed
                $table->timestamp('last_reply_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'last_reply_at']);
            });
        }

        if (!Schema::hasTable('support_messages')) {
            Schema::create('support_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_staff')->default(false);
                $table->text('body');
                $table->timestamps();
                $table->index(['support_ticket_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        if (Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['last_seen_at']);
                $table->dropColumn('last_seen_at');
            });
        }
        Schema::dropIfExists('app_settings');
    }
};
