<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 4 §31 — Lesson Q&A threads (additive, data-safe).
 *
 * Threaded questions/answers per lesson. `parent_id` links an answer to its
 * question. Deletion is MODERATED (soft): `is_deleted` + `deleted_by` keep the
 * audit trail; academic data is never destroyed. Rollback: drop the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lesson_questions')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_deleted')->default(false);
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['lesson_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_questions');
    }
};
