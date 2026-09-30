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

        // ONE outcome definition for every screen (ExamAttempt::outcome()).
        $outcome = $this->resource->outcome();

        return [
            'attempt_id' => $this->id,
            'exam_id' => $this->exam_id,
            'status' => $this->status?->value,
            'grades_published' => $this->grades_published_at !== null,
            'score' => $this->when($isPublished, $this->score),
            'percentage' => $this->when($isPublished, $this->percentage),
            // The official semantic outcome (passed / failed / pending_review /
            // disqualified / expired) — identical in analytics and teacher views.
            'outcome' => $isPublished || $isStaff ? $outcome->value : null,
            'passed' => $this->when(
                $isPublished && $outcome->isDefinitive(),
                fn () => $outcome->isPassed()
            ),
            'end_reason' => $this->end_reason,
            'grades_published_at' => $this->grades_published_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
