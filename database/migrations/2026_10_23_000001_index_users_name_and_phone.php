<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The students list sorts and searches by name, and flags duplicate names and
 * phone numbers on every page. Neither column was indexed, so each of those
 * read the whole users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('name', 'users_name_index');
            $table->index('phone', 'users_phone_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_name_index');
            $table->dropIndex('users_phone_index');
        });
    }
};
