<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Morning planning and evening shutdown records (one of each per user and day). Additive only:
 * no existing table is changed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('day_rituals')) return;
        Schema::create('day_rituals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('ritual_date');
            $t->string('kind', 10);                 // morning | evening
            $t->string('status', 12)->default('completed'); // completed | skipped
            $t->unsignedTinyInteger('energy')->nullable(); // 1–5
            $t->unsignedTinyInteger('mood')->nullable();   // 1–5 (evening)
            $t->string('intention', 500)->nullable();      // morning: what would make today a good day
            $t->text('wins')->nullable();                  // evening: what went well
            $t->text('notes')->nullable();
            $t->json('top_task_ids')->nullable();          // morning: up to 3 chosen tasks
            $t->json('tomorrow_task_ids')->nullable();     // evening: up to 3 for tomorrow
            $t->json('moves')->nullable();                 // what was done with unfinished tasks
            $t->json('summary')->nullable();               // evening: server-computed snapshot of the day
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'ritual_date', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_rituals');
    }
};
