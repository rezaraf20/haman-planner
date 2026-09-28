<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External calendars. Tokens are stored encrypted (application-level, APP_KEY).
 * Imported events live apart from planner tasks; they only mark busy time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('calendar_connections')) {
            Schema::create('calendar_connections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 30);                 // google
                $table->string('account_email')->nullable();
                $table->text('access_token')->nullable();       // encrypted
                $table->text('refresh_token')->nullable();      // encrypted
                $table->timestamp('token_expires_at')->nullable();
                $table->string('calendar_id')->nullable();      // calendar imported as busy time
                $table->string('calendar_name')->nullable();
                $table->string('export_calendar_id')->nullable(); // calendar planner blocks are written to
                $table->boolean('import_enabled')->default(true);
                $table->boolean('export_enabled')->default(false);
                $table->string('status', 20)->default('active'); // active | error | revoked
                $table->timestamp('last_synced_at')->nullable();
                $table->string('last_error', 500)->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'provider']);
            });
        }
        if (!Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
                $table->string('provider_event_id');
                $table->string('title')->nullable();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->boolean('all_day')->default(false);
                $table->boolean('is_busy')->default(true);
                $table->timestamps();
                $table->unique(['calendar_connection_id', 'provider_event_id']);
                $table->index(['user_id', 'starts_at']);
            });
        }
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'calendar_feed_token')) {
                $table->string('calendar_feed_token', 64)->nullable()->unique(); // sha256 of the private iCal feed token
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'calendar_feed_token')) {
                $table->dropUnique(['calendar_feed_token']);
                $table->dropColumn('calendar_feed_token');
            }
        });
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_connections');
    }
};
