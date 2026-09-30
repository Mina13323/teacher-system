<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'unit_id' => $this->unit_id,
            'lesson_id' => $this->lesson_id,
            'title' => $this->title,
            'description' => $this->description,
            'points' => $this->points,
            'due_at' => $this->due_at?->toISOString(),
            'is_published' => $this->is_published,
            'is_past_due' => $this->isPastDue(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            // Staff-facing counts (omitted for students for a quieter payload).
            'submissions_count' => $this->when($request->user()?->isStaff(), fn () => [
                'total' => (int) ($this->submissions_count ?? 0),
                'submitted' => (int) ($this->submitted_count ?? 0),
                'graded' => (int) ($this->graded_count ?? 0),
            ]),
        ];
    }
}
