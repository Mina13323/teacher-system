<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frozen per-attempt copy of the integrity settings that applied when
        // the attempt started. A teacher later changing the live exam settings
        // must not silently change the rules of an existing attempt.
        Schema::create('exam_attempt_integrity_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('exam_attempts')->cascadeOnDelete();
            $table->boolean('fullscreen_required')->default(false);
            $table->boolean('prevent_copy')->default(false);
            $table->boolean('prevent_paste')->default(false);
            $table->boolean('prevent_context_menu')->default(false);
            $table->boolean('detect_tab_switch')->default(true);
            $table->boolean('detect_window_blur')->default(true);
            $table->boolean('detect_keyboard_shortcuts')->default(false);
            $table->timestamps();

            $table->unique('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_integrity_settings');
    }
};
