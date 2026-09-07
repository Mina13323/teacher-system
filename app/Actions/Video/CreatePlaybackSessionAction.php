<?php

namespace App\Actions\Video;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoPlaybackSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Issues a short-lived video playback session for an already-authorized student.
 *
 * Authorization is the caller's responsibility (see VideoPolicy::play), which is
 * re-evaluated on every request — that is the real security boundary. This action
 * only materialises the short-lived session handle used by the protected player
 * (and as the watermark/audit anchor). The handle is intentionally ephemeral; it
 * does NOT secure the underlying media (that is provider-level, see docs).
 */
class CreatePlaybackSessionAction
{
    public function execute(Video $video, User $student): VideoPlaybackSession
    {
        $ttl = max(5, (int) Config::get('video.playback_session_ttl_minutes', 30));

        // Shed any already-expired sessions for this student+video so the
        // table does not accumulate stale rows.
        VideoPlaybackSession::query()
            ->where('video_id', $video->getKey())
            ->where('student_id', $student->getKey())
            ->where(function ($q) {
                $q->whereNotNull('revoked_at')->orWhere('expires_at', '<', now());
            })
            ->delete();

        return VideoPlaybackSession::create([
            'video_id' => $video->getKey(),
            'student_id' => $student->getKey(),
            'token' => Str::random(48),
            'expires_at' => now()->addMinutes($ttl),
        ]);
    }
}
