<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI providers managed in Admin → AI (keys encrypted, priority/fallback, monthly token budget,
 * optional prices for cost estimates) and a per-call usage log (tokens only — no prompt or answer text).
 * With no rows in ai_providers the app keeps using AI_* from .env exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_providers')) {
            Schema::create('ai_providers', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 80);
                $table->string('driver', 30);                  // openai | gemini | groq | openrouter | xai | deepseek | custom
                $table->string('base_url')->nullable();
                $table->text('api_key');                        // encrypted
                $table->string('model', 120);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('priority')->default(10); // lower is tried first; the rest are fallbacks
                $table->unsignedBigInteger('monthly_token_budget')->nullable();
                $table->decimal('input_price_per_million', 10, 4)->nullable();  // USD per 1M prompt tokens
                $table->decimal('output_price_per_million', 10, 4)->nullable(); // USD per 1M completion tokens
                $table->json('balance')->nullable();
                $table->timestamp('balance_checked_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_error_at')->nullable();
                $table->string('last_error', 300)->nullable();
                $table->timestamps();
                $table->index(['is_active', 'priority']);
            });
        }
        if (!Schema::hasTable('ai_usage')) {
            Schema::create('ai_usage', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
                $table->string('provider', 80);
                $table->string('model', 120);
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('feature', 40)->nullable();
                $table->unsignedInteger('prompt_tokens')->default(0);
                $table->unsignedInteger('completion_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->boolean('success')->default(true);
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('error', 200)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['ai_provider_id', 'created_at']);
                $table->index('created_at');
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_providers');
    }
};
