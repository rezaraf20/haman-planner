<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('reviews', function(Blueprint $table) {
   $table->id(); $table->string('type'); $table->date('period_start'); $table->date('period_end');
   $table->text('summary')->nullable(); $table->json('metrics_json')->nullable(); $table->json('actions_json')->nullable(); $table->timestamps();
   $table->index(['type','period_start','period_end']);
  });
 }
 public function down(): void { Schema::dropIfExists('reviews'); }
};