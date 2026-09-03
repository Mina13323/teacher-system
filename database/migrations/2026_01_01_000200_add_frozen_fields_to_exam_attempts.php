<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            // The pass threshold frozen at the moment the attempt started. Once
            // an attempt is created later changes to the exam's pass_percentage
            // must NOT affect the attempt's pass/fail determination.
            $table->unsignedInteger('pass_percentage')->default(50)->after('percentage');

            // Denormalized, non-fillable key that guarantees at most one
            // IN_PROGRESS attempt per (student, exam) at the database level.
            // Value is `{student_id}:{exam_id}` while in progress, otherwise null.
            // Multiple NULLs are allowed by a unique index.
            $table->string('active_key')->nullable()->after('status');
        });

        // Backfill the frozen pass threshold from the owning exam for any
        // pre-existing attempts. On a clean database this is a no-op.
        $attempts = DB::table('exam_attempts')
            ->select('id', 'exam_id')
            ->get();

        foreach ($attempts as $attempt) {
            $examPass = DB::table('exams')
                ->where('id', $attempt->exam_id)
                ->value('pass_percentage');

            DB::table('exam_attempts')
                ->where('id', $attempt->id)
                ->update(['pass_percentage' => $examPass ?? 50]);
        }

        // Backfill the active key for any stale in-progress attempts. On a clean
        // database this is a no-op.
        $active = DB::table('exam_attempts')
            ->where('status', 'in_progress')
            ->select('id', 'student_id', 'exam_id')
            ->get();

        foreach ($active as $attempt) {
            DB::table('exam_attempts')
                ->where('id', $attempt->id)
                ->update(['active_key' => $attempt->student_id.':'.$attempt->exam_id]);
        }

        // The unique index is added after the backfill to avoid transient
        // collisions from pre-existing duplicate in-progress attempts.
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->unique('active_key');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropUnique(['active_key']);
            $table->dropColumn(['active_key', 'pass_percentage']);
        });
    }
};
