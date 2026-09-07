<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the student/teacher account-management fields.
 *
 *  - profile fields a user may complete themselves (phone, bio) and a marker
 *    (profile_completed_at) that reflects a completed profile,
 *  - `created_by` records which teacher (or admin) created a student account so
 *    a teacher can manage only the students they created, while still being able
 *    to see/manage those enrolled in courses they own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('avatar');
            $table->text('bio')->nullable()->after('phone');
            $table->timestamp('profile_completed_at')->nullable()->after('bio');
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->index('created_by');
        });

        // The creator of a student/teacher account is another user. Deleting the
        // creator must not destroy the account; null it out.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['phone', 'bio', 'profile_completed_at', 'created_by']);
        });
    }
};
