<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A short-lived, server-issued playback authorization. It is created only
        // after the student passes the full server-side video access policy, and
        // it expires on its own. It never grants provider-level security — it is
        // the *application-level* access handle (and the audit/watermark anchor),
        // separated from provider-level media security.
        Schema::create('video_playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'expires_at']);
            $table->index('video_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_playback_sessions');
    }
};
