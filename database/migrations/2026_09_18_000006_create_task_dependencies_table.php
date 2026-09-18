<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('task_dependencies', function(Blueprint $table) {
   $table->id();
   $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
   $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
   $table->string('type')->default('requires');
   $table->timestamps();
   $table->unique(['task_id','depends_on_task_id','type']);
  });
 }
 public function down(): void { Schema::dropIfExists('task_dependencies'); }
};