<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRODUCTION DATA SAFETY (additive):
 *  - Adds three nullable/defaulted columns to `exam_attempts`. No existing row
 *    is modified; historical attempts keep grading exactly as before
 *    (`raw_percentage` NULL ⇒ pass/fail falls back to the stored rounded
 *    `percentage`, so no historical result changes).
 *  - `violation_warnings` starts at 0 for existing rows; only new integrity
 *    events increment it.
 *  - `end_reason` is NULL for historical rows (their end state is already
 *    described by `status`).
 *
 * Rollback: dropping these columns loses warning counters / end reasons /
 * raw precision values for attempts created after this migration only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            // Full-precision percentage for pass/fail decisions (display
            // `percentage` stays an integer). NULL on legacy rows.
            $table->decimal('raw_percentage', 6, 3)->nullable()->after('percentage');
            // Warning counter for the interruption warning policy (P0.5).
            $table->unsignedInteger('violation_warnings')->default(0)->after('risk_score');
            // How the attempt ended: submitted_by_student | auto_submit_at_deadline
            // | expired | integrity_threshold | terminated_on_request ...
            $table->string('end_reason', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn(['raw_percentage', 'violation_warnings', 'end_reason']);
        });
    }
};
