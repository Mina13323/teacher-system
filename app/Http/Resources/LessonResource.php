<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Lesson
 */
class LessonResource extends JsonResource
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
            'unit_id' => $this->unit_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            // Lesson body content. Authored by the teacher and safe for any
            // user who can see the lesson (student access is enforced by the
            // controllers/policies, which is where enrollment is checked).
            'content' => $this->content,
            'position' => $this->position,
            'is_published' => $this->is_published,
            'videos_count' => $this->whenCounted('videos'),
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'file_name' => $a->file_name,
                'file_mime' => $a->file_mime,
                'file_size' => $a->file_size,
                'position' => $a->position,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
