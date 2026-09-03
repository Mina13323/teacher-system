<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The attempt snapshot is a self-contained historical representation of
        // the exam version at the moment the attempt started. It stores its own
        // copies of question_text / points / position / option_text / is_correct.
        //
        // The `question_id` / `option_id` references below are therefore weak,
        // denormalized references to the LIVE question/option records that were
        // snapshotted. They must NOT cascade when a teacher deletes a live
        // question/option, otherwise a teacher's edit would destroy an in-progress
        // or already-submitted attempt's history.
        //
        // We drop the FK constraints (and the `nullOnDelete` on option_id so a
        // deleted option does not null out historical answers). The columns are
        // left in place because the snapshot and answer records reference them by
        // value; the live rows are no longer the source of truth.
        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            $table->dropForeign(['question_id']);
        });

        Schema::table('exam_attempt_options', function (Blueprint $table) {
            $table->dropForeign(['option_id']);
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropForeign(['question_id']);
            $table->dropForeign(['option_id']);
        });
    }

    public function down(): void
    {
        // Restore the original constraints so the migration is reversible. Note
        // this reintroduces the destructive behavior and should only be used to
        // roll back to the pre-hardening schema.
        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
        });

        Schema::table('exam_attempt_options', function (Blueprint $table) {
            $table->foreign('option_id')->references('id')->on('options')->cascadeOnDelete();
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->foreign('option_id')->references('id')->on('options')->nullOnDelete();
        });
    }
};
