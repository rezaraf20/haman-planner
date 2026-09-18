<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->unsignedSmallInteger('max_attempts')->default(3)->after('attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('scheduled_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table): void {
            $table->dropIndex(['next_attempt_at']);
            $table->dropColumn(['attempts','max_attempts','next_attempt_at']);
        });
    }
};
