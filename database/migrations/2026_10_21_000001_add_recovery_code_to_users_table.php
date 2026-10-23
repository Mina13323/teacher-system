<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service password recovery for students.
 *
 *  - `recovery_code_hash`: keyed hash of the code the student was shown (never
 *    the code itself).
 *  - `recovery_code_confirmed_at`: set once the student confirmed they saved
 *    the code. Until then the code is not usable and is shown again (as a
 *    fresh one) on their next visit.
 *  - `recovery_verified_at`: set when the code and phone were verified; lets
 *    the forced password change that follows skip the "current password".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recovery_code_hash', 64)->nullable();
            $table->timestamp('recovery_code_confirmed_at')->nullable();
            $table->timestamp('recovery_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['recovery_code_hash', 'recovery_code_confirmed_at', 'recovery_verified_at']);
        });
    }
};
