<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('ai_interactions', function(Blueprint $table) {
   $table->id(); $table->string('provider'); $table->string('model')->nullable(); $table->string('intent')->nullable();
   $table->string('input_hash',64)->index(); $table->json('input_payload')->nullable(); $table->json('output_payload')->nullable();
   $table->decimal('confidence',5,4)->nullable(); $table->string('status')->default('completed'); $table->timestamp('created_at')->useCurrent();
  });
 }
 public function down(): void { Schema::dropIfExists('ai_interactions'); }
};