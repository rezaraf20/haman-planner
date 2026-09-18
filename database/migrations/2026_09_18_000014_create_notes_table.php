<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('notes', function(Blueprint $table) {
   $table->id(); $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
   $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
   $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
   $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
   $table->string('title'); $table->longText('content'); $table->timestamps();
  });
 }
 public function down(): void { Schema::dropIfExists('notes'); }
};