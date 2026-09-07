<?php

namespace App\Actions\Video;

use App\Enums\VideoPlaybackEventType;
use App\Exceptions\InvalidVideoPlaybackSessionException;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoPlaybackEvent;
use App\Models\VideoPlaybackSession;

/**
 * Records content-protection / audit events for protected video playback.
 *
 * Server-authoritative events (granted / denied) are created directly by the
 * controller. Client-reportable deterrence detections are created only after
 * verifying that the supplied session belongs to the authenticated student AND
 * the route video, and is still active — so a student can never record events
 * against another student's session, and there is no session-lookup surface.
 *
 * The record contains only the video, student, optional session, event type and
 * timestamp. No provider id, clipboard, keystrokes or personal data is stored.
 */
class RecordVideoPlaybackEventAction
{
    /**
     * Record a server-authoritative playback outcome (granted / denied).
     */
    public function recordOutcome(
        Video $video,
        User $student,
        VideoPlaybackEventType $type,
        ?VideoPlaybackSession $session = null
    ): void {
        if (! in_array($type, [VideoPlaybackEventType::PlaybackGranted, VideoPlaybackEventType::PlaybackDenied, VideoPlaybackEventType::SessionExpired], true)) {
            return;
        }

        VideoPlaybackEvent::create([
            'video_id' => $video->getKey(),
            'student_id' => $student->getKey(),
            'session_id' => $session?->getKey(),
            'event_type' => $type->value,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Record a client-reported deterrence detection against the student's own
     * active session for this video.
     */
    public function recordClientDetection(
        User $student,
        Video $video,
        VideoPlaybackSession $session,
        VideoPlaybackEventType $type
    ): VideoPlaybackEvent {
        if (! $type->isClientReportable()) {
            throw new InvalidVideoPlaybackSessionException('This event type is recorded server-side.');
        }

        // Audit the event only from an active session that this student owns and
        // which belongs to the route video. This prevents cross-student tampering
        // and any session-lookup/enumeration.
        if (
            (int) $session->student_id !== (int) $student->getKey()
            || (int) $session->video_id !== (int) $video->getKey()
        ) {
            throw new InvalidVideoPlaybackSessionException('This playback session does not belong to you.');
        }

        if (! $session->isActive()) {
            throw new InvalidVideoPlaybackSessionException('This playback session is no longer active.');
        }

        return VideoPlaybackEvent::create([
            'video_id' => $video->getKey(),
            'student_id' => $student->getKey(),
            'session_id' => $session->getKey(),
            'event_type' => $type->value,
            'occurred_at' => now(),
        ]);
    }
}
