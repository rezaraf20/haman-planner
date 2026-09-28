<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Private task/project attachments (files live on a non-public disk under random names). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attachments')) return;
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 40);   // task | project
            $table->unsignedBigInteger('attachable_id');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 120);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index(['user_id', 'attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
