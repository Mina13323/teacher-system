<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempt_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_question_id')->constrained('exam_attempt_questions')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->cascadeOnDelete();
            // Frozen copy of the option as it appeared at attempt start.
            $table->string('option_text');
            $table->boolean('is_correct');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['attempt_question_id', 'option_id']);
            $table->index(['attempt_question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_options');
    }
};
