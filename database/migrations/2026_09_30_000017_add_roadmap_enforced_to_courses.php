<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 4 §35 — Roadmap enforcement switch (additive, data-safe).
 *
 * DECISION: roadmap locking is INFORMATIONAL by default (current production
 * behaviour, preserved) and becomes ACCESS ENFORCEMENT only for courses that
 * explicitly enable `roadmap_enforced`. Teacher/admin/assistant preview is
 * always allowed. Existing rows default to false — zero behaviour change on
 * deploy. Rollback: drop the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('roadmap_enforced')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('roadmap_enforced');
        });
    }
};
