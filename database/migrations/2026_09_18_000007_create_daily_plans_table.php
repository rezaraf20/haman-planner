<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('daily_plans', function(Blueprint $table) {
   $table->id();
   $table->date('plan_date')->unique();
   $table->unsignedInteger('available_minutes')->default(0);
   $table->unsignedInteger('planned_minutes')->default(0);
   $table->unsignedInteger('completed_minutes')->default(0);
   $table->unsignedInteger('buffer_minutes')->default(0);
   $table->unsignedTinyInteger('focus_level')->nullable();
   $table->unsignedTinyInteger('energy_level')->nullable();
   $table->text('notes')->nullable();
   $table->timestamps();
  });
 }
 public function down(): void { Schema::dropIfExists('daily_plans'); }
};