<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-select answers for `multiple_choice` questions.
 *
 * PRODUCTION DATA SAFETY (additive):
 *  - Creates a NEW table only. No existing table, column, index or row is touched.
 *  - Existing single-select answers keep living in `exam_answers.option_id` and
 *    remain authoritative for historical grading: rows in this table are only
 *    written from now on for multi-select (and single-select) answers going
 *    forward. Grading falls back to `exam_answers.option_id` when a row has no
 *    children, so every historical attempt grades exactly as it did before.
 *  - `option_id` intentionally carries NO foreign key to `options`, matching the
 *    snapshot protection policy (migration 2026_01_01_000201): it references the
 *    frozen option-id space of the attempt snapshot, and a teacher deleting a
 *    live option must never destroy recorded student answers.
 *
 * Rollback: dropping this table loses only multi-select selections recorded
 * after this migration; legacy single-select data is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_answer_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->constrained('exam_answers')->cascadeOnDelete();
            // Snapshot-space option id (no FK by design; see docblock).
            $table->unsignedBigInteger('option_id');
            $table->timestamps();

            // A selection set cannot contain the same option twice.
            $table->unique(['answer_id', 'option_id']);
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answer_options');
    }
};
