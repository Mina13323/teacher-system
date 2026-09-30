<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
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
 */
class LessonController extends Controller
{
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('access', $lesson);

        $lesson->load(['attachments' => function ($query) {
            $query->orderBy('position');
        }]);

        return $this->success(new LessonResource($lesson), 'Lesson retrieved.');
    }
}
