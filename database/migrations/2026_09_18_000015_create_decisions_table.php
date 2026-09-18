<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('decisions', function(Blueprint $table) {
   $table->id(); $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
   $table->string('title'); $table->text('decision'); $table->text('rationale')->nullable(); $table->timestamp('decided_at'); $table->timestamps();
  });
 }
 public function down(): void { Schema::dropIfExists('decisions'); }
};