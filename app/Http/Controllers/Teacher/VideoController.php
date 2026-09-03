<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Video\CreateVideoAction;
use App\Actions\Video\ReorderVideosAction;
use App\Actions\Video\UpdateVideoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateVideoRequest;
use App\Http\Requests\ReorderVideosRequest;
use App\Http\Requests\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Lesson;
use App\Models\Video;
use Illuminate\Http\JsonResponse;

class VideoController extends Controller
{
    public function __construct(
        private readonly CreateVideoAction $createVideo,
        private readonly UpdateVideoAction $updateVideo,
        private readonly ReorderVideosAction $reorderVideos,
    ) {
    }

    public function index(Lesson $lesson): JsonResponse
    {
        $this->authorize('view', $lesson);

        $videos = $lesson->videos()->orderBy('position')->get();

        return $this->success(VideoResource::collection($videos), 'Videos retrieved.');
    }

    public function store(CreateVideoRequest $request, Lesson $lesson): JsonResponse
    {
        $video = $this->createVideo->execute($lesson, $request->validated());

        return $this->success(new VideoResource($video), 'Video created.', 201);
    }

    public function show(Video $video): JsonResponse
    {
        $this->authorize('view', $video);

        $video->load('lesson');

        return $this->success(new VideoResource($video), 'Video retrieved.');
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        $video = $this->updateVideo->execute($video, $request->validated());

        return $this->success(new VideoResource($video), 'Video updated.');
    }

    public function publish(Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        $video->update(['is_published' => true]);

        return $this->success(new VideoResource($video), 'Video published.');
    }

    public function unpublish(Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        $video->update(['is_published' => false]);

        return $this->success(new VideoResource($video), 'Video unpublished.');
    }

    public function reorder(ReorderVideosRequest $request, Lesson $lesson): JsonResponse
    {
        $this->reorderVideos->execute($lesson, $request->validated('ordered_ids'));

        return $this->success(null, 'Videos reordered.');
    }

    public function destroy(Video $video): JsonResponse
    {
        $this->authorize('delete', $video);

        $video->delete();

        return $this->success(null, 'Video deleted.');
    }
}
