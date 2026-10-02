<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student row may not be deleted while exam attempts still reference it.
 * History removal must go through the audited, highest-admin force-delete flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
            $table->foreign('student_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
            $table->foreign('student_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
