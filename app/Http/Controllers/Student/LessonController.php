<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LessonProgressResource;
use App\Http\Resources\LessonResource;
use App\Http\Resources\StudentVideoResource;
use App\Models\Bookmark;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student lesson content access.
 *
 * This is the endpoint the student lesson page reads the lesson body
 * (`content`) from. It is gated on every request by LessonPolicy::access —
 * account active, lesson published, student enrolled in the lesson's course —
 * so a lesson id from another course or an unpublished lesson is never
 * readable. Only student-safe fields are exposed (LessonResource carries no
 * teacher-only metadata).
 *
 * `?include=progress,videos,bookmark` adds, in the same request, what the
 * lesson page otherwise asked three more endpoints for: the student's own
 * progress (as GET .../progress, without the nested lesson), the published
 * video list (as GET .../videos: learning metadata only, never a playable
 * reference — that still comes only from the protected playback endpoint)
 * and the id of the student's own bookmark on this lesson. Everything is
 * behind the same LessonPolicy::access check, which is at least as strict as
 * those endpoints'. Without `include` the response is unchanged.
 */
class LessonController extends Controller
{
    private const INCLUDES = ['progress', 'videos', 'bookmark'];

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('access', $lesson);

        $lesson->load(['attachments' => function ($query) {
            $query->orderBy('position');
        }]);

        $include = array_intersect(
            self::INCLUDES,
            array_map('trim', explode(',', (string) $request->query('include', '')))
        );

        if ($include === []) {
            return $this->success(new LessonResource($lesson), 'Lesson retrieved.');
        }

        $userId = (int) $request->user()->getKey();
        $data = (new LessonResource($lesson))->resolve($request);

        if (in_array('progress', $include, true)) {
            $data['progress'] = (new LessonProgressResource(
                LessonProgress::forStudentLesson($userId, (int) $lesson->getKey())
            ))->resolve($request);
        }

        if (in_array('videos', $include, true)) {
            $data['videos'] = StudentVideoResource::collection($lesson->publishedVideos())->resolve($request);
        }

        if (in_array('bookmark', $include, true)) {
            // The lesson-level bookmark (not a video timestamp), own rows only.
            $bookmarkId = Bookmark::query()
                ->where('user_id', $userId)
                ->where('lesson_id', $lesson->getKey())
                ->whereNull('video_id')
                ->latest('id')
                ->value('id');
            $data['bookmark'] = $bookmarkId !== null ? ['id' => (int) $bookmarkId] : null;
        }

        return $this->success($data, 'Lesson retrieved.');
    }
}
