<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable audit trail of teacher integrity reviews. A new row is
        // inserted for every review; historical review records are never
        // overwritten.
        Schema::create('exam_integrity_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['attempt_id', 'reviewed_at']);
            $table->index('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_integrity_reviews');
    }
};
