<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->after('course_id')->constrained()->nullOnDelete();
            $table->json('unit_ids')->nullable()->after('lesson_id');
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex(['lesson_id']);
            $table->dropConstrainedForeignId('lesson_id');
            $table->dropColumn('unit_ids');
        });
    }
};
