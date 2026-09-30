<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for content entities (courses, units, lessons, exams).
 *
 * PRODUCTION DATA SAFETY (additive):
 *  - Adds a nullable `deleted_at` column; nothing existing is modified. Eloquent
 *    `SoftDeletes` then hides deleted rows from listings WITHOUT destroying
 *    anything: exam attempts, answers and grades keep pointing at their exam,
 *    and read paths use `withTrashed()` where history must stay visible.
 *  - Hard delete (forceDelete) is intentionally NOT exposed for exams/courses:
 *    attempts carry `cascadeOnDelete` foreign keys and a hard delete would take
 *    historical attempts with it.
 *  - Users/students deliberately do NOT get soft deletes: they are auth
 *    identities with a unique email and live tokens; their archive mechanism is
 *    suspend/restore + deactivate, which already exists and is tested.
 *
 * Rollback: dropping `deleted_at` makes previously archived rows visible again;
 * no data is lost.
 */
return new class extends Migration
{
    private const TABLES = ['courses', 'units', 'lessons', 'exams'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
