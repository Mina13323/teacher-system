<?php

namespace App\Http\Requests;

use App\Models\ExamAttempt;
use Illuminate\Foundation\Http\FormRequest;

class SubmitExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var ExamAttempt $attempt */
        $attempt = $this->route('attempt');

        return $this->user()->can('update', $attempt);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // question_id/option_id are validated against the attempt's frozen
        // snapshot inside SaveExamAnswerAction. We deliberately do NOT use
        // `exists:questions,id` / `exists:options,id` here, because a teacher
        // may legitimately delete a live question/option while a student is
        // mid-attempt; the snapshot (and its ability to be answered) must
        // survive. Snapshot membership is the source of truth.
        return [
            'question_id' => ['required', 'integer'],
            'option_id' => ['required', 'integer'],
        ];
    }
}
