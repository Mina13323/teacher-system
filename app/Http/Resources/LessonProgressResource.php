<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\LessonProgress
 */
class LessonProgressResource extends JsonResource
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
            'student_id' => $this->student_id,
            'lesson_id' => $this->lesson_id,
            'completed' => $this->completed,
            'progress_percentage' => $this->progress_percentage,
            'last_position_seconds' => $this->last_position_seconds,
            'completed_at' => $this->completed_at?->toISOString(),
            'lesson' => $this->whenLoaded('lesson', fn () => new LessonResource($this->lesson)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
