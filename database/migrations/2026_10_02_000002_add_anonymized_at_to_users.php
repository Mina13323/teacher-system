<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-002: Add a durable `anonymized_at` marker to the users table.
 *
 * Once set this column permanently records that the account has been
 * anonymized and prevents ordinary activation, credential regeneration,
 * and profile-update actions from touching the account again.
 *
 * The column is nullable (NULL = not anonymized; non-null = timestamp of
 * anonymization). It is added with a default of null so all existing rows
 * remain un-anonymized without touching data.
 *
 * SAFETY: additive, no existing row is modified. Rollback removes the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('profile_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
