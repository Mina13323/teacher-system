<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Submit/immediate-result resource. Only returned when the exam is configured
 * with show_result_immediately = true, or when re-submitting an already
 * submitted attempt. Contains no per-question answer key.
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
        return [
            'attempt_id' => $this->id,
            'exam_id' => $this->exam_id,
            'status' => $this->status?->value,
            'score' => $this->score,
            'percentage' => $this->percentage,
            // pass/fail uses the attempt's frozen pass threshold, never the
            // current exam config.
            'passed' => $this->when(
                $this->percentage !== null && $this->pass_percentage !== null,
                fn () => $this->percentage >= $this->pass_percentage
            ),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
        ];
    }
}
