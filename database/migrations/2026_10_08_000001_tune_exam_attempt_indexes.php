<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index tuning for exam traffic. CONTROLLED DEPLOYMENT: run outside exam
 * hours, after checking `SHOW INDEX FROM exam_attempts` and
 * `SHOW INDEX FROM exam_answers` on the server (production may differ from
 * the migration history). Every step is guarded, so a missing or already
 * changed index is skipped instead of failing the deploy.
 *
 * Adds:
 *  - exam_attempts (exam_id, started_at): the teacher attempt list filters by
 *    exam and orders by started_at; today it sorts every attempt of the exam.
 *
 * Drops two EXACT duplicates, which every answer save, start and submit pays
 * for on write without any read using them:
 *  - exam_answers (attempt_id, question_id) plain index: the unique index on
 *    the same columns serves every read and the foreign key.
 *  - exam_attempts_exam_status_idx: the same columns as
 *    exam_attempts_exam_id_status_index.
 *
 * Prefix-only duplicates (exam_answers_attempt_idx, exam_attempts_student_id_index,
 * exam_attempts_student_exam_idx) are left alone: removing them is a smaller
 * gain and touches indexes a foreign key may be using.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('exam_attempts', 'exam_attempts_exam_started_idx')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->index(['exam_id', 'started_at'], 'exam_attempts_exam_started_idx');
            });
        }

        if (
            Schema::hasIndex('exam_answers', 'exam_answers_attempt_id_question_id_index')
            && Schema::hasIndex('exam_answers', 'exam_answers_attempt_id_question_id_unique')
        ) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->dropIndex('exam_answers_attempt_id_question_id_index');
            });
        }

        if (
            Schema::hasIndex('exam_attempts', 'exam_attempts_exam_status_idx')
            && Schema::hasIndex('exam_attempts', 'exam_attempts_exam_id_status_index')
        ) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->dropIndex('exam_attempts_exam_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('exam_attempts', 'exam_attempts_exam_status_idx')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->index(['exam_id', 'status'], 'exam_attempts_exam_status_idx');
            });
        }

        if (! Schema::hasIndex('exam_answers', 'exam_answers_attempt_id_question_id_index')) {
            Schema::table('exam_answers', function (Blueprint $table) {
                $table->index(['attempt_id', 'question_id'], 'exam_answers_attempt_id_question_id_index');
            });
        }

        if (Schema::hasIndex('exam_attempts', 'exam_attempts_exam_started_idx')) {
            Schema::table('exam_attempts', function (Blueprint $table) {
                $table->dropIndex('exam_attempts_exam_started_idx');
            });
        }
    }
};
