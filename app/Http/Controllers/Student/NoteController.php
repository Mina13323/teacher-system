<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentNoteResource;
use App\Models\StudentNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 4 §32 — Private student notes. Owner-only (StudentNotePolicy):
 * no staff surface exists, by design.
 */
class NoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentNote::class);

        $notes = StudentNote::query()
            ->where('user_id', $request->user()->getKey())
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->integer('lesson_id')))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->success(StudentNoteResource::collection($notes), 'Notes retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', StudentNote::class);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        $note = StudentNote::create([
            'user_id' => $request->user()->getKey(),
            'course_id' => $data['course_id'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'video_id' => $data['video_id'] ?? null,
            'position_seconds' => $data['position_seconds'] ?? null,
            'body' => $data['body'],
        ]);

        return $this->success(new StudentNoteResource($note), 'Note saved.', 201);
    }

    public function update(Request $request, StudentNote $note): JsonResponse
    {
        $this->authorize('update', $note);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);
        $note->forceFill(['body' => $data['body']])->save();

        return $this->success(new StudentNoteResource($note), 'Note updated.');
    }

    public function destroy(Request $request, StudentNote $note): JsonResponse
    {
        $this->authorize('delete', $note);
        $note->delete();

        return $this->success(null, 'Note deleted.');
    }
}
