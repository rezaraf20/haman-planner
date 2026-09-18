<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('execution_logs', function(Blueprint $table) {
   $table->id();
   $table->foreignId('task_id')->constrained()->cascadeOnDelete();
   $table->timestamp('started_at');
   $table->timestamp('ended_at')->nullable();
   $table->unsignedInteger('duration_minutes')->default(0);
   $table->unsignedTinyInteger('focus_level')->nullable();
   $table->unsignedTinyInteger('energy_level')->nullable();
   $table->string('result')->nullable();
   $table->text('blocker')->nullable();
   $table->text('notes')->nullable();
   $table->timestamps();
   $table->index(['task_id','started_at']);
  });
 }
 public function down(): void { Schema::dropIfExists('execution_logs'); }
};