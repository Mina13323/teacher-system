<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Teacher-facing attempt detail. Includes the full per-question breakdown and
 * the correctness of each answer. Only returned for attempts belonging to
 * exams the teacher manages.
 *
 * @mixin \App\Models\ExamAttempt
 */
class ExamAttemptDetailResource extends JsonResource
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
            'exam_id' => $this->exam_id,
            'exam_title' => $this->whenLoaded('exam', fn () => $this->exam->title),
            'student' => $this->whenLoaded('student', fn () => new UserResource($this->student)),
            'attempt_number' => $this->attempt_number,
            'status' => $this->status?->value,
            'score' => $this->score,
            'percentage' => $this->percentage,
            'pass_percentage' => $this->pass_percentage,
            'integrity_status' => $this->integrity_status?->value,
            'risk_score' => $this->risk_score,
            // pass/fail uses the attempt's frozen pass threshold, never the
            // current exam config.
            'passed' => $this->when(
                $this->percentage !== null && $this->pass_percentage !== null,
                fn () => $this->percentage >= $this->pass_percentage
            ),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'answers' => $this->answers->map(fn ($answer) => [
                'question_id' => $answer->question_id,
                'option_id' => $answer->option_id,
                'is_correct' => $answer->is_correct,
                'points_earned' => $answer->points_earned,
                'answered_at' => $answer->answered_at?->toISOString(),
            ])->values(),
        ];
    }
}
