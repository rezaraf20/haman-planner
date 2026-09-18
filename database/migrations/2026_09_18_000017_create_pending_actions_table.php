<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 100)->index();
            $table->string('intent');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['chat_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
