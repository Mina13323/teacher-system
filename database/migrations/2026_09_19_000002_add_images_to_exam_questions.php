<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('question_text');
        });
        Schema::table('exam_attempt_questions', function (Blueprint $table) {
            $table->string('question_image_path')->nullable()->after('question_text');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempt_questions', fn (Blueprint $table) => $table->dropColumn('question_image_path'));
        Schema::table('questions', fn (Blueprint $table) => $table->dropColumn('image_path'));
    }
};
