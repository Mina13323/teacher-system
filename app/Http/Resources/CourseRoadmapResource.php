<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Interactive roadmap representation for an enrolled student.
 *
 * Assumes the wrapped course has been enriched with a roadmap and progress via
 * BuildCourseRoadmapAction.
 *
 * @mixin \App\Models\Course
 */
class CourseRoadmapResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'course' => [
                'id' => $this->id,
                'title' => $this->title,
                'slug' => $this->slug,
                'thumbnail' => $this->thumbnail,
                'status' => $this->status?->value,
            ],
            'progress' => $this->roadmap_progress ?? 0,
            'units' => $this->roadmap ?? [],
        ];
    }
}
