<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the optional official exam window (starts_at / ends_at).
 *
 * Timing semantics
 * ----------------
 * Windowed exam (both timestamps set):
 *     global_deadline    = starts_at + duration_minutes
 *     effective_deadline = min(global_deadline, ends_at)
 *   A student entering late loses the elapsed time; the attempt's expires_at is
 *   set to the effective deadline, never to (entry time + duration).
 *
 * Legacy exam (starts_at IS NULL and ends_at IS NULL) — Option A, chosen
 * explicitly for backward compatibility:
 *     expires_at = started_at + duration_minutes
 *   Existing exams keep their current duration-per-attempt behaviour and are
 *   never silently converted to global-clock mode.
 *
 * Partial windows (exactly one of the two set) are rejected at validation time
 * in CreateExamRequest / UpdateExamRequest, so they cannot be created.
 *
 * Timestamps are stored and compared in the application timezone (UTC, see
 * config/app.php). Clients must send ISO-8601 with an explicit offset; the
 * browser's local timezone is never used to authorize access.
 *
 * This migration is purely additive: it does not modify or drop any existing
 * column, table, or row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            if (! Schema::hasColumn('exams', 'starts_at')) {
                $table->timestamp('starts_at')->nullable()->after('duration_minutes');
            }
            if (! Schema::hasColumn('exams', 'ends_at')) {
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $columns = array_filter(
                ['starts_at', 'ends_at'],
                fn (string $column) => Schema::hasColumn('exams', $column)
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
