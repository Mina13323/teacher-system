<?php

namespace App\Http\Controllers\Student;

use App\Actions\Video\CreatePlaybackSessionAction;
use App\Actions\Video\RecordVideoPlaybackEventAction;
use App\Enums\VideoPlaybackEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordVideoPlaybackEventRequest;
use App\Http\Resources\StudentVideoResource;
use App\Http\Resources\VideoPlaybackResource;
use App\Models\Lesson;
use App\Models\Video;
use App\Models\VideoPlaybackSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Protected student video access. This is the ONLY student-facing surface that
 * yields a playable media reference, and it is gated server-side on every
 * request:
 *
 *   - account active,
 *   - course & lesson & video published,
 *   - student enrolled in the video's course,
 *   - video belongs to that lesson/course.
 *
 * A student can never obtain a playable reference by changing the video id,
 * lesson id, or course id — authorization is re-evaluated each time (no cached
 * grant), and playback sessions are short-lived.
 *
 * There is intentionally NO provider-id lookup or session-token lookup endpoint:
 * a student can neither enumerate provider ids nor read another student's
 * session. Client-reported deterrence detections are accepted only against the
 * student's OWN active session for the route video.
 */
class VideoController extends Controller
{
    public function __construct(
        private readonly CreatePlaybackSessionAction $createSession,
        private readonly RecordVideoPlaybackEventAction $recordEvent,
    ) {
    }

    /**
     * List the published videos of a lesson the student is authorized to access.
     * Returns only minimal learning metadata — no provider/media reference.
     */
    public function index(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('access', $lesson);

        $videos = $lesson->videos()
            ->where('is_published', true)
            ->orderBy('position')
            ->get();

        return $this->success(StudentVideoResource::collection($videos), 'Videos retrieved.');
    }

    /**
     * Issue a short-lived playback session for an authorized video. The response
     * contains the minimum playable reference plus the content-protection config.
     */
    public function playback(Request $request, Video $video): JsonResponse
    {
        $student = $request->user();

        try {
            $this->authorize('play', $video);
        } catch (AuthorizationException $e) {
            // Server-authoritative audit of a denied playback attempt.
            $this->recordEvent->recordOutcome($video, $student, VideoPlaybackEventType::PlaybackDenied);
            throw $e;
        }

        $session = $this->createSession->execute($video, $student);
        $this->recordEvent->recordOutcome($video, $student, VideoPlaybackEventType::PlaybackGranted, $session);

        return $this->success(new VideoPlaybackResource($session), 'Playback authorized.', 201);
    }

    /**
     * Record a client-reported content-protection detection (deterrence signal)
     * against the student's own active playback session for this video. The
     * event is scoped and throttled; it contains no provider reference.
     */
    public function recordEvent(RecordVideoPlaybackEventRequest $request, Video $video): JsonResponse
    {
        $student = $request->user();

        $session = VideoPlaybackSession::query()
            ->where('token', $request->string('session_token')->toString())
            ->firstOrFail();

        $type = VideoPlaybackEventType::from($request->validated('event_type'));

        $event = $this->recordEvent->recordClientDetection($student, $video, $session, $type);

        return $this->success([
            'recorded' => true,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ], 'Playback protection event recorded.', 201);
    }
}
