<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A course as seen by an enrolled student.
 *
 * In list mode it exposes lightweight metadata plus the computed progress. When
 * a roadmap has been built onto the model (via BuildCourseRoadmapAction) the
 * units and their lesson states are included, enabling course access details.
 *
 * @mixin \App\Models\Course
 */
class StudentCourseResource extends JsonResource
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
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail' => $this->thumbnail,
            'status' => $this->status?->value,
            'units_count' => $this->whenCounted('units'),
            'lessons_count' => $this->whenCounted('lessons'),
            'progress' => $this->progress ?? 0,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($this->roadmap !== null) {
            $data['units'] = $this->roadmap;
        }

        return $data;
    }
}
