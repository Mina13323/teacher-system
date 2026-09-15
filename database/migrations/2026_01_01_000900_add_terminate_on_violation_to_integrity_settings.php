<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an explicit "end the attempt on a serious violation" switch.
 *
 * Detecting a tab switch is only half the story: without this flag a detected
 * violation is recorded but the student carries on answering. Teachers can now
 * choose whether a violation merely raises the risk score or terminates the
 * attempt immediately.
 *
 * Defaults to true on both tables, so existing exams gain the stricter
 * behaviour on upgrade. The value is frozen onto each attempt when the attempt
 * starts, exactly like every other integrity setting, so changing it later
 * never rewrites the rules of an attempt already in progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_integrity_settings', function (Blueprint $table) {
            $table->boolean('terminate_on_violation')->default(true)->after('detect_keyboard_shortcuts');
        });

        Schema::table('exam_attempt_integrity_settings', function (Blueprint $table) {
            $table->boolean('terminate_on_violation')->default(true)->after('detect_keyboard_shortcuts');
        });
    }

    public function down(): void
    {
        Schema::table('exam_integrity_settings', function (Blueprint $table) {
            $table->dropColumn('terminate_on_violation');
        });

        Schema::table('exam_attempt_integrity_settings', function (Blueprint $table) {
            $table->dropColumn('terminate_on_violation');
        });
    }
};
