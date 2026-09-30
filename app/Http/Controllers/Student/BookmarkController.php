<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookmarkResource;
use App\Models\Bookmark;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 4 §33 — Personal bookmarks (lesson or video timestamp). Owner-only.
 */
class BookmarkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bookmark::class);

        $bookmarks = Bookmark::query()
            ->where('user_id', $request->user()->getKey())
            ->with(['lesson:id,title,unit_id', 'video:id,title,lesson_id'])
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return $this->success(BookmarkResource::collection($bookmarks), 'Bookmarks retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Bookmark::class);

        $data = $request->validate([
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'video_id' => ['nullable', 'integer', 'exists:videos,id'],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        if (($data['lesson_id'] ?? null) === null && ($data['video_id'] ?? null) === null) {
            return response()->json([
                'success' => false,
                'message' => 'A bookmark must reference a lesson or a video.',
                'data' => null,
            ], 422);
        }

        // Idempotent: bookmarking the same place twice keeps one row.
        $bookmark = Bookmark::updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                'lesson_id' => $data['lesson_id'] ?? null,
                'video_id' => $data['video_id'] ?? null,
                'position_seconds' => $data['position_seconds'] ?? null,
            ],
            ['label' => $data['label'] ?? null],
        );

        return $this->success(new BookmarkResource($bookmark), 'Bookmark saved.', 201);
    }

    public function destroy(Request $request, Bookmark $bookmark): JsonResponse
    {
        $this->authorize('delete', $bookmark);
        $bookmark->delete();

        return $this->success(null, 'Bookmark removed.');
    }
}
