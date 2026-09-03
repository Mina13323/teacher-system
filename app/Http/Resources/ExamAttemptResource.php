<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing student/exam attempt resource. Contains the attempt's
 * questions + options in their frozen snapshot order. NEVER exposes
 * `is_correct` or the answer key.
 *
 * @mixin \App\Models\ExamAttempt
 */
class ExamAttemptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $studentAnswers = $this->answers->pluck('option_id', 'question_id');

        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'exam_title' => $this->whenLoaded('exam', fn () => $this->exam->title),
            'attempt_number' => $this->attempt_number,
            'status' => $this->status?->value,
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'duration_minutes' => $this->whenLoaded('exam', fn () => $this->exam->duration_minutes),
            'questions' => $this->attemptQuestions->map(fn ($attemptQuestion) => [
                'id' => $attemptQuestion->question_id,
                'attempt_question_id' => $attemptQuestion->id,
                'question_text' => $attemptQuestion->question_text,
                'points' => $attemptQuestion->points,
                'position' => $attemptQuestion->position,
                'options' => $attemptQuestion->attemptOptions->map(fn ($attemptOption) => [
                    'id' => $attemptOption->option_id,
                    'option_text' => $attemptOption->option_text,
                    'position' => $attemptOption->position,
                    'selected' => ($studentAnswers->get($attemptQuestion->question_id) === $attemptOption->option_id),
                ])->values(),
            ])->values(),
        ];
    }
}
