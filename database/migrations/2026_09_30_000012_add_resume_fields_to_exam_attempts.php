<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 1 §12-13 — Teacher override for integrity-terminated attempts
 * (additive, data-safe).
 *
 * When a teacher resumes a flagged attempt, the SAME attempt continues. These
 * columns record the override provenance; the original integrity events,
 * warning history and the prior termination are never deleted — the previous
 * end_reason and expiry are copied here before the attempt is re-opened.
 *
 * Existing data impact: none (nullable adds). Backfill: not needed — NULL
 * means "never resumed". Rollback: drop the columns; no other data references
 * them. Deployment order: migrate any time (old code ignores the columns).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('resumed_at')->nullable()->after('end_reason');
            $table->foreignId('resumed_by')->nullable()->after('resumed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('resume_note', 2000)->nullable()->after('resumed_by');
            $table->string('previous_end_reason', 40)->nullable()->after('resume_note');
            $table->timestamp('previous_expires_at')->nullable()->after('previous_end_reason');
            $table->unsignedInteger('time_restored_seconds')->nullable()->after('previous_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resumed_by');
            $table->dropColumn([
                'resumed_at', 'resume_note', 'previous_end_reason',
                'previous_expires_at', 'time_restored_seconds',
            ]);
        });
    }
};
