<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 4 §33 — Personal bookmarks (additive, data-safe).
 *
 * A student can bookmark a lesson or a video timestamp. Owner-only (policy).
 * Rollback: drop the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('videos')->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->string('label', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
