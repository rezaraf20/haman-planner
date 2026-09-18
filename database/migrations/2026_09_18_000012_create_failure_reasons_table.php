<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('failure_reasons', function(Blueprint $table) {
   $table->id(); $table->string('code')->unique(); $table->string('name');
   $table->string('preventable')->default('partial'); $table->unsignedTinyInteger('severity')->default(1); $table->timestamps();
  });
 }
 public function down(): void { Schema::dropIfExists('failure_reasons'); }
};