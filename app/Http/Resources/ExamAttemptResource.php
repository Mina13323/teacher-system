<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Student-facing student/exam attempt resource. Contains the attempt's
 * questions + options in their frozen snapshot order.
 *
 * Answer-key policy: `is_correct` and the per-question review payload are
 * NEVER exposed before grades are published. After publication they are
 * exposed only when the exam's `allow_answer_review` switch is on — the
 * documented post-publication review feature. Unpublished attempts leak
 * nothing about correctness.
 *
 * @mixin \App\Models\ExamAttempt
 */
class ExamAttemptResource extends JsonResource
{
    /**
     * Whether the post-publication answer review payload is enabled for this
     * attempt: grades must be published AND the exam must allow review.
     */
    private function reviewEnabled(): bool
    {
        if ($this->grades_published_at === null) {
            return false;
        }

        $exam = $this->relationLoaded('exam') ? $this->exam : null;

        return $exam === null || $exam->answerReviewEnabled();
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answersByQuestion = $this->answers->keyBy('question_id');

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
            // The frozen proctoring rules for THIS attempt. Configuration only:
            // no risk score, no severity, no integrity status and no review
            // history — those stay server-side. The client needs the flags to
            // know which protections to apply and whether a violation ends the
            // attempt, and telling the student what is monitored is both a
            // deterrent and a fairness requirement.
            'integrity_rules' => $this->whenLoaded('integritySetting', fn () => [
                'fullscreen_required' => (bool) $this->integritySetting->fullscreen_required,
                'prevent_copy' => (bool) $this->integritySetting->prevent_copy,
                'prevent_paste' => (bool) $this->integritySetting->prevent_paste,
                'prevent_context_menu' => (bool) $this->integritySetting->prevent_context_menu,
                'detect_tab_switch' => (bool) $this->integritySetting->detect_tab_switch,
                'detect_window_blur' => (bool) $this->integritySetting->detect_window_blur,
                'detect_keyboard_shortcuts' => (bool) $this->integritySetting->detect_keyboard_shortcuts,
                'terminate_on_violation' => (bool) $this->integritySetting->terminate_on_violation,
                'violation_warning_threshold' => $this->integritySetting->violation_warning_threshold
                    ?? (int) config('integrity.warning_threshold', 5),
            ]),
            // The published result. Strictly gated on grades_published_at, the
            // same gate ExamResultResource and the attempts list use, so an
            // unpublished score still never reaches the student. Without these
            // fields a student returning to a graded attempt saw a permanent
            // "under review" message, because this endpoint carried no score.
            'grades_published' => $this->grades_published_at !== null,
            'score' => $this->when($this->grades_published_at !== null, $this->score),
            'percentage' => $this->when($this->grades_published_at !== null, $this->percentage),
            // Official outcome — same source of truth as analytics and the
            // teacher screens (ExamAttempt::outcome()).
            'outcome' => $this->grades_published_at !== null ? $this->resource->outcome()->value : null,
            'passed' => $this->when(
                $this->grades_published_at !== null && $this->resource->outcome()->isDefinitive(),
                fn () => $this->resource->outcome()->isPassed()
            ),
            'end_reason' => $this->end_reason,
            'resumed_at' => $this->resumed_at?->toISOString(),
            'resumed_by' => $this->resumed_by,
            'resume_note' => $this->resume_note,
            'previous_end_reason' => $this->previous_end_reason,
            'previous_expires_at' => $this->previous_expires_at?->toISOString(),
            'time_restored_seconds' => $this->time_restored_seconds,
            'questions' => $this->attemptQuestions->map(function ($attemptQuestion) use ($answersByQuestion) {
                $answer = $answersByQuestion->get($attemptQuestion->question_id);
                // Multi-select aware selection set (falls back to the legacy
                // single option_id for rows written before it existed).
                $selectedIds = $answer ? $answer->selectedOptionIds() : [];

                return [
                    'id' => $attemptQuestion->question_id,
                    'attempt_question_id' => $attemptQuestion->id,
                    'question_text' => $attemptQuestion->question_text,
                    'image_url' => $attemptQuestion->question_image_path
                        ? \Illuminate\Support\Facades\Storage::disk('public')->url($attemptQuestion->question_image_path)
                        : null,
                    'question_type' => $attemptQuestion->question_type ?? 'single_choice',
                    'points' => $attemptQuestion->points,
                    'position' => $attemptQuestion->position,
                    'selected_option_id' => $answer?->option_id,
                    'selected_option_ids' => $selectedIds,
                    'answer_text' => $answer?->answer_text,
                    // Post-publication answer review (P0.4). Strictly gated:
                    // nothing here exists before grades are published, and the
                    // exam's `allow_answer_review` switch can disable the whole
                    // review payload. Teacher-only metadata (grader identity)
                    // is deliberately never included.
                    'review' => $this->reviewEnabled()
                        ? [
                            'is_correct' => $this->reviewEnabled() ? ($answer?->is_correct) : null,
                            'points_earned' => $answer !== null ? (int) $answer->points_earned : 0,
                            'feedback' => $answer?->feedback,
                            'graded_at' => $answer?->graded_at?->toISOString(),
                            'explanation' => $attemptQuestion->question?->reference_answer,
                        ]
                        : null,
                    'options' => $attemptQuestion->attemptOptions->map(fn ($attemptOption) => [
                        'id' => $attemptOption->option_id,
                        'option_text' => $attemptOption->option_text,
                        'position' => $attemptOption->position,
                        'selected' => in_array((int) $attemptOption->option_id, $selectedIds, true),
                        // Answer key only during review (see gate above).
                        $this->mergeWhen($this->reviewEnabled(), ['is_correct' => (bool) $attemptOption->is_correct]),
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
