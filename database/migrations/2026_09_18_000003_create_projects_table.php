<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->unsignedTinyInteger('importance')->default(50);
            $table->decimal('weight', 8, 2)->default(1);
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('health')->default('on_track');
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamps();
            $table->index(['status', 'target_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('projects'); }
};
