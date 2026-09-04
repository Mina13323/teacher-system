<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Video
 */
class VideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'duration' => $this->duration,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        // The internal storage path is an implementation detail and is only
        // exposed to authenticated teachers/admins, never in the public course
        // catalog or student-facing responses.
        if ($this->isStaffView($request)) {
            $data['storage_path'] = $this->storage_path;
        }

        return $data;
    }

    private function isStaffView(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('teacher') || $user->hasRole('admin');
    }
}
