<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tags (many per task) and saved task filters ("smart lists"). Additive only.
 * A filter's definition is a small, validated JSON object (see TaskFilter) — never SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);
            $t->string('color', 20)->nullable();
            $t->string('icon', 16)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });
        Schema::create('task_tag', function (Blueprint $t) {
            $t->foreignId('task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->primary(['task_id', 'tag_id']);
            $t->index('tag_id');
        });
        Schema::create('saved_filters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('icon', 16)->nullable();
            $t->json('definition');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
        Schema::dropIfExists('task_tag');
        Schema::dropIfExists('tags');
    }
};
