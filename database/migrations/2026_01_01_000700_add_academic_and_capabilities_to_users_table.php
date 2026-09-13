<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'student_code')) {
                $table->string('student_code', 64)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('users', 'academic_year')) {
                $table->string('academic_year', 32)->nullable()->after('bio');
                $table->index('academic_year');
            }
            if (! Schema::hasColumn('users', 'can_access_lessons')) {
                $table->boolean('can_access_lessons')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('users', 'can_take_exams')) {
                $table->boolean('can_take_exams')->default(true)->after('can_access_lessons');
            }
            if (! Schema::hasColumn('users', 'can_join_competitions')) {
                $table->boolean('can_join_competitions')->default(true)->after('can_take_exams');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['student_code', 'academic_year', 'can_access_lessons', 'can_take_exams', 'can_join_competitions'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
