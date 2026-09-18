<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('schedule_blocks', function(Blueprint $table) {
   $table->id();
   $table->foreignId('daily_plan_id')->nullable()->constrained()->nullOnDelete();
   $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
   $table->timestamp('starts_at');
   $table->timestamp('ends_at');
   $table->string('source')->default('planner');
   $table->string('status')->default('planned');
   $table->timestamps();
   $table->index(['starts_at','ends_at']);
  });
 }
 public function down(): void { Schema::dropIfExists('schedule_blocks'); }
};