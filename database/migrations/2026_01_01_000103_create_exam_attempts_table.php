<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('score')->nullable();
            $table->unsignedInteger('percentage')->nullable();
            $table->string('status')->default('in_progress');
            $table->timestamps();

            // A student can only have one attempt with a given number per exam.
            $table->unique(['student_id', 'exam_id', 'attempt_number']);
            $table->index(['exam_id', 'status']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
