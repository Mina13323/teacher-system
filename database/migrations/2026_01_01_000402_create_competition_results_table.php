<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('competition_participants')->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            // Server-derived values. The client never supplies score, percentage,
            // completion time, rank or qualification.
            $table->unsignedInteger('score');
            $table->unsignedInteger('percentage');
            $table->unsignedInteger('completion_time')->default(0); // seconds
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->boolean('qualified')->default(true);
            $table->timestamps();

            // Exactly one final result per participant per competition.
            $table->unique(['competition_id', 'participant_id']);

            // Indexes for the ranked leaderboard read model.
            $table->index(['competition_id', 'rank']);
            $table->index(['competition_id', 'score', 'completion_time', 'completed_at', 'participant_id']);
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competition_results');
    }
};
