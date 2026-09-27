<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: provider-agnostic billing.
 *  plans          – configurable catalogue (names, prices per currency, limits, features)
 *  subscriptions  – a user's entitlement period for a plan (trial / paid / manual)
 *  payments       – one checkout attempt with a provider (pending → paid / failed / canceled)
 *  invoices       – issued for every paid payment; kept (anonymised) after account deletion
 *  usage_counters – metered usage per user, metric and month (e.g. AI requests)
 * Amounts are integers in the currency's smallest unit (USD cents, IRT tomans).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 40)->unique();
                $table->json('name');
                $table->json('description')->nullable();
                $table->json('prices')->nullable();
                $table->json('limits')->nullable();
                $table->json('features')->nullable();
                $table->unsignedSmallInteger('trial_days')->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_public')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
                $table->string('status', 20); // trialing | active | canceled | expired
                $table->string('billing_interval', 10)->nullable(); // monthly | yearly | null (trial/manual)
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('current_period_start')->nullable();
                $table->timestamp('current_period_end')->nullable();
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamp('canceled_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('provider', 30)->nullable();
                $table->string('provider_reference', 191)->nullable();
                $table->timestamp('expiry_notified_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'current_period_end']);
            });
        }

        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
                $table->string('billing_interval', 10);
                $table->string('provider', 30);
                $table->string('provider_reference', 191)->nullable();
                $table->string('transaction_reference', 191)->nullable();
                $table->unsignedBigInteger('amount');
                $table->string('currency', 3);
                $table->string('status', 20)->default('pending'); // pending | paid | failed | canceled | refunded
                $table->string('failure_reason', 500)->nullable();
                $table->string('customer_email')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->index(['provider', 'provider_reference']);
                $table->index(['user_id', 'created_at']);
                $table->index(['status', 'paid_at']);
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('number', 40)->unique();
                $table->unsignedBigInteger('amount');
                $table->string('currency', 3);
                $table->string('billing_name')->nullable();
                $table->string('billing_email')->nullable();
                $table->json('lines');
                $table->timestamp('issued_at');
                $table->timestamps();
                $table->index(['user_id', 'issued_at']);
            });
        }

        if (!Schema::hasTable('usage_counters')) {
            Schema::create('usage_counters', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('metric', 50);
                $table->string('period', 7); // YYYY-MM
                $table->unsignedInteger('used')->default(0);
                $table->timestamps();
                $table->unique(['user_id', 'metric', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
