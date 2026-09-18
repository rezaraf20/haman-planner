<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedTinyInteger('importance')->default(50);
            $table->decimal('weight', 8, 2)->default(1);
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('health')->default('on_track');
            $table->text('success_criteria')->nullable();
            $table->timestamps();
            $table->index(['status', 'target_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('goals'); }
};
