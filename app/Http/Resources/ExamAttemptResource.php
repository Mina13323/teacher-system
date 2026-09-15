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
            ]),
            'questions' => $this->attemptQuestions->map(function ($attemptQuestion) use ($answersByQuestion) {
                $answer = $answersByQuestion->get($attemptQuestion->question_id);

                return [
                    'id' => $attemptQuestion->question_id,
                    'attempt_question_id' => $attemptQuestion->id,
                    'question_text' => $attemptQuestion->question_text,
                    'question_type' => $attemptQuestion->question_type ?? 'single_choice',
                    'points' => $attemptQuestion->points,
                    'position' => $attemptQuestion->position,
                    'selected_option_id' => $answer?->option_id,
                    'answer_text' => $answer?->answer_text,
                    'options' => $attemptQuestion->attemptOptions->map(fn ($attemptOption) => [
                        'id' => $attemptOption->option_id,
                        'option_text' => $attemptOption->option_text,
                        'position' => $attemptOption->position,
                        'selected' => ($answer?->option_id === $attemptOption->option_id),
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
