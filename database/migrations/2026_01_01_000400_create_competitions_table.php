<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // A competition draws its scoring source from an existing exam. The
            // FK is RESTRICT so a referenced exam cannot be deleted while a
            // competition depends on it, preserving historical results.
            $table->foreignId('exam_id')->constrained('exams')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->string('scoring_type')->default('highest_score');
            $table->string('ranking_type')->default('score_desc');
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index('created_by');
            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
