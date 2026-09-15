<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exam attempt result resource. Scores and pass/fail indicators are strictly hidden
 * from students until staff publishes grades (grades_published_at is non-null).
 *
 * @mixin \App\Models\ExamAttempt
 */
class ExamResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isStaff = $request->user()?->isStaff() || $request->user()?->isAdmin();
        $isPublished = $this->grades_published_at !== null || $isStaff;

        return [
            'attempt_id' => $this->id,
            'exam_id' => $this->exam_id,
            'status' => $this->status?->value,
            'grades_published' => $this->grades_published_at !== null,
            'score' => $this->when($isPublished, $this->score),
            'percentage' => $this->when($isPublished, $this->percentage),
            'passed' => $this->when(
                $isPublished && $this->percentage !== null && $this->pass_percentage !== null,
                fn () => $this->percentage >= $this->pass_percentage
            ),
            'grades_published_at' => $this->grades_published_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
