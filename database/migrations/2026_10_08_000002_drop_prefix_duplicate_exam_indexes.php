<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops three indexes whose columns are the leading columns of a UNIQUE index
 * on the same table. CONTROLLED DEPLOYMENT, separate from
 * 2026_10_08_000001 so it can be held back on its own: run it outside exam
 * hours, after `SHOW INDEX FROM exam_attempts` and `SHOW INDEX FROM
 * exam_answers` on the server.
 *
 *  - exam_answers_attempt_idx (attempt_id): leading column of
 *    exam_answers_attempt_id_question_id_unique. Every answer insert pays
 *    for it.
 *  - exam_attempts_student_exam_idx (student_id, exam_id) and
 *    exam_attempts_student_id_index (student_id): leading columns of
 *    exam_attempts_student_id_exam_id_attempt_number_unique.
 *
 * Any read or foreign key that used them is served by the unique index, which
 * starts with the same columns (checked on MariaDB 10.11: the foreign keys on
 * attempt_id and student_id stay valid after the drop). Each step runs only
 * when the covering unique index exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasIndex('exam_answers', 'exam_answers_attempt_idx')
            && Schema::hasIndex('exam_answers', 'exam_answers_attempt_id_question_id_unique')
        ) {
            Schema::table('exam_answers', fn (Blueprint $table) => $table->dropIndex('exam_answers_attempt_idx'));
        }

        if (Schema::hasIndex('exam_attempts', 'exam_attempts_student_id_exam_id_attempt_number_unique')) {
            foreach (['exam_attempts_student_exam_idx', 'exam_attempts_student_id_index'] as $index) {
                if (Schema::hasIndex('exam_attempts', $index)) {
                    Schema::table('exam_attempts', fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('exam_attempts', 'exam_attempts_student_id_index')) {
            Schema::table('exam_attempts', fn (Blueprint $table) => $table->index('student_id', 'exam_attempts_student_id_index'));
        }

        if (! Schema::hasIndex('exam_attempts', 'exam_attempts_student_exam_idx')) {
            Schema::table('exam_attempts', fn (Blueprint $table) => $table->index(['student_id', 'exam_id'], 'exam_attempts_student_exam_idx'));
        }

        if (! Schema::hasIndex('exam_answers', 'exam_answers_attempt_idx')) {
            Schema::table('exam_answers', fn (Blueprint $table) => $table->index('attempt_id', 'exam_answers_attempt_idx'));
        }
    }
};
