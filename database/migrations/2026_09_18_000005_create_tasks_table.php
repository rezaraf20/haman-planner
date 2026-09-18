<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('inbox');
            $table->string('priority')->default('p2');
            $table->unsignedTinyInteger('importance')->default(50);
            $table->decimal('weight', 8, 2)->default(1);
            $table->decimal('progress', 5, 2)->default(0);
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->timestamp('planned_start')->nullable();
            $table->timestamp('planned_end')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->unsignedTinyInteger('energy_level')->nullable();
            $table->unsignedTinyInteger('focus_level')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority', 'deadline']);
            $table->index(['goal_id', 'project_id', 'milestone_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('tasks'); }
};
