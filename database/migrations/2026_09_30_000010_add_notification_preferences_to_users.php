<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user notification preferences + quiet hours.
 *
 * JSON shape (all keys optional; missing key = default):
 *   {
 *     "exam_reminders": true,
 *     "assignment_reminders": true,
 *     "competition_reminders": true,
 *     "result_notifications": true,
 *     "quiet_hours": { "start": "22:00", "end": "07:00" }
 *   }
 *
 * Quiet hours suppress *scheduled reminders* (never in-app records of grade
 * publications the student must see). Defaults are "everything on, no quiet
 * hours", so existing users keep receiving exactly what they receive today.
 *
 * PRODUCTION DATA SAFETY: additive nullable column; no row modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
