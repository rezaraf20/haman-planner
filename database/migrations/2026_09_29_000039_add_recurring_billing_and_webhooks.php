<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Provider-managed (auto-renewing) subscriptions and an idempotency log for webhooks. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            if (!Schema::hasColumn('subscriptions', 'provider_customer_id')) $table->string('provider_customer_id')->nullable();
            if (!Schema::hasColumn('subscriptions', 'auto_renew')) $table->boolean('auto_renew')->default(false);
            if (!Schema::hasColumn('subscriptions', 'past_due_at')) $table->timestamp('past_due_at')->nullable();
        });
        if (!Schema::hasIndex('subscriptions', 'subscriptions_provider_provider_reference_index')) {
            Schema::table('subscriptions', fn (Blueprint $t) => $t->index(['provider', 'provider_reference']));
        }
        if (!Schema::hasTable('webhook_events')) {
            Schema::create('webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 30);
                $table->string('event_id');
                $table->string('type', 100);
                $table->string('status', 20)->default('received'); // received | processed | ignored | failed
                $table->string('error', 500)->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'event_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        if (Schema::hasIndex('subscriptions', 'subscriptions_provider_provider_reference_index')) {
            Schema::table('subscriptions', fn (Blueprint $t) => $t->dropIndex('subscriptions_provider_provider_reference_index'));
        }
        Schema::table('subscriptions', function (Blueprint $table): void {
            foreach (['provider_customer_id', 'auto_renew', 'past_due_at'] as $c) {
                if (Schema::hasColumn('subscriptions', $c)) $table->dropColumn($c);
            }
        });
    }
};
