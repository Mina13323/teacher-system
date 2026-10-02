<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive exam operations and MCQ explanation support.
 *
 * Existing attempts, answers, scores and deadlines are not rewritten. The
 * attempt soft-delete columns are an administrative visibility/audit feature;
 * answers remain attached and are never cascaded by a soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('explanation_enabled')->default(false);
            $table->boolean('explanation_required')->default(false);
        });

        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            // Frozen with the question so edits to an archived/re-published
            // paper cannot change the contract of an existing attempt.
            $table->boolean('explanation_enabled')->default(false);
            $table->boolean('explanation_required')->default(false);
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            // Kept separately from answer_text (essay response) and option_id.
            $table->text('explanation')->nullable();
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rules_acknowledged_at')->nullable();
        });

        Schema::create('exam_make_up_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->unique()->constrained('exam_attempts')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('used_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('assigned');
            // Only one valid assignment per exam/student at a time. Clearing
            // this nullable unique key on use/revocation preserves history and
            // permits a later, deliberate additional assignment.
            $table->string('active_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['exam_id', 'student_id', 'status'], 'exam_makeup_exam_student_status_idx');
            $table->index(['student_id', 'status'], 'exam_makeup_student_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_make_up_assignments');

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('rules_acknowledged_at');
            $table->dropSoftDeletes();
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropColumn('explanation');
        });

        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            $table->dropColumn(['explanation_enabled', 'explanation_required']);
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['explanation_enabled', 'explanation_required']);
        });
    }
};
