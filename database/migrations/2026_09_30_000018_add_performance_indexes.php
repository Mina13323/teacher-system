<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 5 §43 — Performance indexes for the measured hot paths (additive).
 *
 * Evidence: the expiry sweep scans (status, expires_at); student dashboards
 * and grouping filter attempts by (student_id, exam_id) and (exam_id, status);
 * answers load per attempt; notifications list per user by recency; audit
 * queries filter by action and time.
 *
 * Existing data impact: none (index-only). Build time locks writes briefly on
 * huge tables — run during low traffic. Rollback: dropIndex by name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index(['student_id', 'exam_id'], 'exam_attempts_student_exam_idx');
            $table->index(['exam_id', 'status'], 'exam_attempts_exam_status_idx');
            $table->index(['status', 'expires_at'], 'exam_attempts_status_expires_idx');
            $table->index('end_reason', 'exam_attempts_end_reason_idx');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->index('attempt_id', 'exam_answers_attempt_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_id', 'created_at'], 'notifications_notifiable_created_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_idx');
            $table->index('action', 'audit_logs_action_idx');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->index('course_id', 'assignments_course_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('exam_attempts_student_exam_idx');
            $table->dropIndex('exam_attempts_exam_status_idx');
            $table->dropIndex('exam_attempts_status_expires_idx');
            $table->dropIndex('exam_attempts_end_reason_idx');
        });
        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropIndex('exam_answers_attempt_idx');
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_created_idx');
        });
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_idx');
            $table->dropIndex('audit_logs_action_idx');
        });
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('assignments_course_idx');
        });
    }
};
