<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Catalog-safe lesson outline; full content is available only via gated routes. */
class LessonPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unit_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'videos_count' => $this->whenCounted('videos'),
            // Students and non-owning staff may see safe video metadata only.
            // Playback references are issued by the separately authorized
            // playback endpoint; management data stays on teacher routes.
            'videos' => StudentVideoResource::collection($this->whenLoaded('videos')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
