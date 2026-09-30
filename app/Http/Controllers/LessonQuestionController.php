<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 4 §31 — Lesson Q&A threads.
 *
 * Route → auth:sanctum → this controller → LessonQuestionPolicy → model.
 * Students ask; students and course staff answer; authors and course staff
 * moderate (soft delete, history kept). Access to the lesson itself is the
 * first gate (`viewAny` on the lesson's ability context).
 */
class LessonQuestionController extends Controller
{
    /** List the question threads of a lesson (questions + their replies). */
    public function index(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('viewAny', [LessonQuestion::class, $lesson]);

        $questions = $lesson->questions()
            ->whereNull('parent_id')
            ->with(['user', 'replies.user'])
            ->orderBy('created_at')
            ->paginate($this->perPage($request));

        return $this->success(
            \App\Http\Resources\LessonQuestionResource::collection($questions),
            'Questions retrieved.'
        );
    }

    public function store(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('create', [LessonQuestion::class, $lesson]);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $question = LessonQuestion::create([
            'lesson_id' => $lesson->getKey(),
            'user_id' => $request->user()->getKey(),
            'body' => $data['body'],
        ]);

        return $this->success(
            new \App\Http\Resources\LessonQuestionResource($question->load('user')),
            'Question posted.',
            201
        );
    }

    /** Reply to a question (student or course staff). */
    public function reply(Request $request, LessonQuestion $question): JsonResponse
    {
        $lesson = $question->lesson;
        abort_if($lesson === null, 404);
        $this->authorize('reply', [LessonQuestion::class, $lesson]);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $reply = LessonQuestion::create([
            'lesson_id' => $lesson->getKey(),
            'user_id' => $request->user()->getKey(),
            'parent_id' => $question->getKey(),
            'body' => $data['body'],
        ]);

        return $this->success(
            new \App\Http\Resources\LessonQuestionResource($reply->load('user')),
            'Reply posted.',
            201
        );
    }

    /** Moderate (soft delete — thread history is preserved). */
    public function destroy(Request $request, LessonQuestion $question): JsonResponse
    {
        $this->authorize('delete', $question);

        $question->forceFill([
            'is_deleted' => true,
            'deleted_by' => $request->user()->getKey(),
            'deleted_at' => now(),
        ])->save();

        return $this->success(null, 'Message removed.');
    }
}
