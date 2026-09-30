<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 4 §32 — Private student notes (additive, data-safe).
 *
 * Notes are PRIVATE to their owner (enforced by StudentNotePolicy) and may
 * point at a course, lesson and/or video timestamp. Rollback: drop the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_notes');
    }
};
