<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Staff-facing attempt detail. Includes the full per-question breakdown,
 * essay answers, correctness, awarded points, and feedback.
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
            'grades_published' => $this->grades_published_at !== null,
            'grades_published_at' => $this->grades_published_at?->toISOString(),
            'score' => $this->score,
            'percentage' => $this->percentage,
            'pass_percentage' => $this->pass_percentage,
            'integrity_status' => $this->integrity_status?->value,
            'risk_score' => $this->risk_score,
            'violation_warnings' => (int) ($this->violation_warnings ?? 0),
            'end_reason' => $this->end_reason,
            // Official outcome — identical semantics to the student result
            // screen and analytics (ExamAttempt::outcome()).
            'outcome' => $this->resource->outcome()->value,
            'passed' => $this->when(
                $this->resource->outcome()->isDefinitive(),
                fn () => $this->resource->outcome()->isPassed()
            ),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'total_points' => $this->relationLoaded('attemptQuestions') && $this->attemptQuestions->isNotEmpty()
                ? (int) $this->attemptQuestions->sum('points')
                : ($this->relationLoaded('exam') ? (int) $this->exam->total_marks : null),
            'questions' => $this->whenLoaded('attemptQuestions', function () {
                $answersByQuestion = $this->relationLoaded('answers') ? $this->answers->keyBy('question_id') : collect();

                return $this->attemptQuestions->map(function ($aq) use ($answersByQuestion) {
                    $ans = $answersByQuestion->get($aq->question_id);
                    $selectedIds = $ans ? $ans->selectedOptionIds() : [];

                    return [
                        'id' => $aq->question_id,
                        'attempt_question_id' => $aq->id,
                        'question_text' => $aq->question_text,
                        'question_image_path' => $aq->question_image_path,
                        'question_type' => $aq->question_type ?? 'single_choice',
                        'points' => (int) $aq->points,
                        'explanation_enabled' => (bool) $aq->explanation_enabled,
                        'explanation_required' => (bool) $aq->explanation_required,
                        'explanation' => $ans?->explanation,
                        'position' => (int) $aq->position,
                        'selected_option_id' => $ans?->option_id,
                        'selected_option_ids' => $selectedIds,
                        'answer_text' => $ans?->answer_text,
                        'is_correct' => $ans?->is_correct,
                        'points_earned' => $ans !== null ? (int) $ans->points_earned : 0,
                        'feedback' => $ans?->feedback,
                        'graded_by' => $ans?->graded_by,
                        'graded_at' => $ans?->graded_at?->toISOString(),
                        'options' => $aq->relationLoaded('attemptOptions') ? $aq->attemptOptions->map(fn ($opt) => [
                            'id' => $opt->option_id,
                            'attempt_option_id' => $opt->id,
                            'option_text' => $opt->option_text,
                            'is_correct' => (bool) $opt->is_correct,
                            'position' => (int) $opt->position,
                            'is_selected' => in_array((int) $opt->option_id, $selectedIds, true),
                        ])->values() : [],
                    ];
                })->values();
            }),
            'answers' => $this->relationLoaded('answers') ? $this->answers->map(fn ($answer) => [
                'question_id' => $answer->question_id,
                'option_id' => $answer->option_id,
                'selected_option_ids' => $answer->selectedOptionIds(),
                'answer_text' => $answer->answer_text,
                'explanation' => $answer->explanation,
                'is_correct' => $answer->is_correct,
                'points_earned' => $answer->points_earned,
                'feedback' => $answer->feedback,
                'graded_by' => $answer->graded_by,
                'graded_at' => $answer->graded_at?->toISOString(),
                'answered_at' => $answer->answered_at?->toISOString(),
            ])->values() : [],
        ];
    }
}
