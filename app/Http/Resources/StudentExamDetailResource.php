<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing exam detail resource for viewing an available exam before
 * starting an attempt. Contains metadata only — no questions, no answer key.
 *
 * @mixin \App\Models\Exam
 */
class StudentExamDetailResource extends JsonResource
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
            'course_id' => $this->course_id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'pass_percentage' => $this->pass_percentage,
            'max_attempts' => $this->max_attempts,
            'shuffle_questions' => $this->shuffle_questions,
            'shuffle_options' => $this->shuffle_options,
            'show_result_immediately' => $this->show_result_immediately,
            'questions_count' => $this->whenCounted('questions'),
            'my_attempts' => $this->whenLoaded('attempts', function () {
                return $this->attempts
                    ->sortByDesc('attempt_number')
                    ->map(fn ($attempt) => [
                        'attempt_number' => $attempt->attempt_number,
                        'status' => $attempt->status?->value,
                        'score' => $attempt->score,
                        'percentage' => $attempt->percentage,
                        'started_at' => $attempt->started_at?->toISOString(),
                        'submitted_at' => $attempt->submitted_at?->toISOString(),
                    ])
                    ->values();
            }),
            'course' => $this->whenLoaded('course', fn () => new CourseResource($this->course)),
        ];
    }
}
