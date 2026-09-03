<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_integrity_settings', function (Blueprint $table) {
            $table->id();
            // One configuration row per exam.
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->boolean('fullscreen_required')->default(false);
            $table->boolean('prevent_copy')->default(false);
            $table->boolean('prevent_paste')->default(false);
            $table->boolean('prevent_context_menu')->default(false);
            $table->boolean('detect_tab_switch')->default(true);
            $table->boolean('detect_window_blur')->default(true);
            $table->boolean('detect_keyboard_shortcuts')->default(false);
            $table->timestamps();

            $table->unique('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_integrity_settings');
    }
};
