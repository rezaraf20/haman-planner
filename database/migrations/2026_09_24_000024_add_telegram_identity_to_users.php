<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('users', function(Blueprint $table){ $table->string('telegram_chat_id',64)->nullable()->unique(); $table->string('telegram_username',255)->nullable(); $table->timestamp('telegram_linked_at')->nullable(); }); }
 public function down(): void { Schema::table('users', function(Blueprint $table){ $table->dropUnique(['telegram_chat_id']); $table->dropColumn(['telegram_chat_id','telegram_username','telegram_linked_at']); }); }
};