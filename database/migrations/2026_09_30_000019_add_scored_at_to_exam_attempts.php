<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADDITIVE: `scored_at` — the idempotency sentinel for server-side grading.
 *
 * Policy (P0.12): auto-submit at the deadline grades the saved answers and the
 * FIRST grading run stamps `scored_at`. Repeated finalization (scheduler tick,
 * late submit, new attempt) must never rewrite that timestamp — it is how we
 * prove "never double-grades" in tests and audits.
 *
 * Deploy order: migrate (nullable column; existing rows stay NULL = "not yet
 * scored", so NO backfill is required or performed). Rollback drops only this
 * column — no historical data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->timestamp('scored_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropColumn('scored_at');
        });
    }
};
