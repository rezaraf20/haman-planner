<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('reminders', function(Blueprint $table) {
   $table->id();
   $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
   $table->string('type');
   $table->timestamp('scheduled_at');
   $table->string('status')->default('pending');
   $table->json('payload')->nullable();
   $table->timestamps();
   $table->index(['status','scheduled_at']);
  });
 }
 public function down(): void { Schema::dropIfExists('reminders'); }
};