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
            // Display-only window fields for the countdown timer. The server
            // stays the sole authority: it re-checks the window on start and
            // rejects saves/submits on an expired attempt.
            'starts_at' => $this->starts_at?->toISOString(),
            'effective_deadline' => $this->effectiveDeadline()?->toISOString(),
            'questions_count' => $this->whenCounted('questions'),
            'my_attempts' => $this->whenLoaded('attempts', function () {
                return $this->attempts
                    ->sortByDesc('attempt_number')
                    ->map(function ($attempt) {
                        // Scores are withheld until staff publish them; see the
                        // identical gate in ExamResultResource.
                        $published = $attempt->grades_published_at !== null;

                        return [
                            'attempt_number' => $attempt->attempt_number,
                            'status' => $attempt->status?->value,
                            'grades_published' => $published,
                            'score' => $published ? $attempt->score : null,
                            'percentage' => $published ? $attempt->percentage : null,
                            'started_at' => $attempt->started_at?->toISOString(),
                            // Server-computed deadline for an in-progress
                            // attempt; drives the resume countdown.
                            'expires_at' => $attempt->expires_at?->toISOString(),
                            'submitted_at' => $attempt->submitted_at?->toISOString(),
                        ];
                    })
                    ->values();
            }),
            'course' => $this->whenLoaded('course', fn () => new CourseResource($this->course)),
        ];
    }
}
