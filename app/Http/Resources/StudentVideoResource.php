<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing video resource. Carries only the learning metadata a student
 * needs to see that a video exists in a lesson. It deliberately NEVER exposes
 * the provider, provider video id, storage path, or any media/embed reference —
 * a student can only obtain a playback reference by calling the protected
 * playback endpoint (after full server-side authorization).
 *
 * @mixin \App\Models\Video
 */
class StudentVideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'duration' => $this->duration,
            'position' => $this->position,
        ];
    }
}
