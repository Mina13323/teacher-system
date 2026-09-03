<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_integrity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->string('event_type');
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->string('severity')->default('low');
            $table->unsignedInteger('risk_points')->default(0);
            $table->timestamps();

            // Useful for teacher review queries and deduplication lookups.
            $table->index(['attempt_id', 'event_type', 'occurred_at']);
            $table->index(['attempt_id', 'occurred_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_integrity_events');
    }
};
