<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact staff-only attempt row for grouped result screens. */
class ExamAttemptSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'attempt_number' => $this->attempt_number,
            'status' => $this->status?->value,
            'grades_published' => $this->grades_published_at !== null,
            'score' => $this->score,
            'percentage' => $this->percentage,
            'outcome' => $this->resource->outcome()->value,
            'passed' => $this->resource->outcome()->isDefinitive()
                ? $this->resource->outcome()->isPassed()
                : null,
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'duration_seconds' => $this->durationSeconds(),
            'end_reason' => $this->end_reason,
            'integrity_status' => $this->integrity_status?->value,
            'violation_warnings' => (int) ($this->violation_warnings ?? 0),
        ];
    }
}
