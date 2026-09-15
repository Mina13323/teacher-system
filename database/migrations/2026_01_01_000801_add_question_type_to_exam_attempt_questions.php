<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('exam_attempt_questions', 'question_type')) {
                $table->string('question_type', 32)->nullable()->after('question_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            if (Schema::hasColumn('exam_attempt_questions', 'question_type')) {
                $table->dropColumn('question_type');
            }
        });
    }
};
