<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds exams.type: 'exam' (default, every existing row) or 'homework'.
 *
 * A homework is an exam with a required window (open/close time), no
 * proctoring, and an attempt that stays open until the window closes. It
 * reuses the exam engine as is (snapshot, answers, grading, publication).
 *
 * PRODUCTION DATA SAFETY: one new column with a default; existing rows become
 * 'exam' and behave exactly as before. Rollback drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exams', 'type')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->string('type', 16)->default('exam')->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exams', 'type')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
    }
};
