<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam lifecycle options for the hardening phase.
 *
 *  - `expiry_mode`: how an attempt whose deadline passed is finalized.
 *      'auto_submit' (default) — saved answers are submitted and graded; the
 *                                 student never loses work to a dead battery.
 *      'expire'                  — legacy strict behavior: the attempt becomes
 *                                 'expired' and is never graded.
 *    Backfilled to 'auto_submit' for ALL existing exams: this is the deliberate,
 *    documented fairness change mandated by the hardening work order ("auto-submit
 *    attempts at the real deadline"). Per-exam opt-out preserves the strict mode.
 *  - `allow_answer_review`: whether students may review their answers (with the
 *    answer key + feedback) after grades are published. Default true.
 *
 * PRODUCTION DATA SAFETY: additive columns only. No attempt rows are modified;
 * historical scores and statuses are untouched. Rollback drops the columns and
 * reverts to implicit legacy behavior ('expire' + review allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->string('expiry_mode', 16)->default('auto_submit')->after('show_result_immediately');
            $table->boolean('allow_answer_review')->default(true)->after('expiry_mode');
        });

        // Fair default for exams that already exist in production. Explicit
        // backfill (not just the column default) so the intent is recorded in
        // the migration history.
        DB::table('exams')->update(['expiry_mode' => 'auto_submit']);
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['expiry_mode', 'allow_answer_review']);
        });
    }
};
