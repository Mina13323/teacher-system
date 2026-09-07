<?php

namespace App\Http\Controllers\Student;

use App\Actions\Video\CreatePlaybackSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentVideoResource;
use App\Http\Resources\VideoPlaybackResource;
use App\Models\Lesson;
use App\Models\Video;
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
 */
class VideoController extends Controller
{
    public function __construct(private readonly CreatePlaybackSessionAction $createSession)
    {
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
        $this->authorize('play', $video);

        $session = $this->createSession->execute($video, $request->user());

        return $this->success(new VideoPlaybackResource($session), 'Playback authorized.', 201);
    }
}
