<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Catalog-safe unit outline; nested lesson bodies are deliberately omitted. */
class UnitPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'title' => $this->title,
            'description' => $this->description,
            'position' => $this->position,
            'lessons_count' => $this->whenCounted('lessons'),
            'lessons' => LessonPreviewResource::collection($this->whenLoaded('lessons')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
