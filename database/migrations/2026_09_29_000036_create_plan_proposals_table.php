<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Proposed plan changes (smart rescheduling / Haman AI). Nothing is applied until the user confirms. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_proposals')) return;
        Schema::create('plan_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                  // day | week | fix
            $table->string('status', 20)->default('pending'); // pending | applied | dismissed | expired
            $table->date('period_start');
            $table->date('period_end');
            $table->json('metrics')->nullable();
            $table->json('actions');                     // proposed actions, each with a stable key
            $table->json('applied_actions')->nullable();
            $table->text('summary')->nullable();         // AI narrative (optional)
            $table->boolean('ai_used')->default(false);
            $table->string('request_id', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_proposals');
    }
};
