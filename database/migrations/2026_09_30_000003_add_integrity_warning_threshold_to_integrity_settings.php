<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-exam / per-attempt warning threshold for the interruption policy.
 *
 * NULL means "use config('integrity.warning_threshold')" so the policy can be
 * changed without code changes and without rewriting existing rows. Frozen
 * copies are written onto attempts at start so a later exam edit cannot change
 * the rules of an attempt already under way (same freeze pattern as the rest of
 * the attempt integrity settings).
 *
 * PRODUCTION DATA SAFETY: additive nullable columns only; existing rows
 * untouched and keep their current frozen flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_integrity_settings', function (Blueprint $table) {
            $table->unsignedInteger('violation_warning_threshold')->nullable()->after('terminate_on_violation');
        });

        Schema::table('exam_attempt_integrity_settings', function (Blueprint $table) {
            $table->unsignedInteger('violation_warning_threshold')->nullable()->after('terminate_on_violation');
        });
    }

    public function down(): void
    {
        Schema::table('exam_integrity_settings', function (Blueprint $table) {
            $table->dropColumn('violation_warning_threshold');
        });

        Schema::table('exam_attempt_integrity_settings', function (Blueprint $table) {
            $table->dropColumn('violation_warning_threshold');
        });
    }
};
