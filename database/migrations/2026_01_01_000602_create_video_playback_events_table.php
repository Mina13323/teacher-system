<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content-protection / audit events for protected video playback. The
        // record only stores what is necessary for auditability and leak
        // attribution: the video, the student, the (optional) playback session,
        // the event type and when it occurred. It NEVER stores a provider id,
        // clipboard contents, keystrokes, or any personal media data.
        Schema::create('video_playback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('video_playback_sessions')->nullOnDelete();
            $table->string('event_type');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['video_id', 'created_at']);
            $table->index(['student_id', 'event_type']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_playback_events');
    }
};
