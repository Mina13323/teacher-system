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
        return [
            'question_id' => ['required', 'integer'],
            // Legacy single-select field. Kept for backward compatibility.
            'option_id' => ['nullable', 'integer'],
            // Multi-select aware field: the full selected option set. When
            // present it is authoritative and `option_id` is ignored.
            'option_ids' => ['nullable', 'array', 'max:10'],
            'option_ids.*' => ['integer'],
            'answer_text' => ['nullable', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            // Opt-in short acknowledgement (only the saved question) instead
            // of the full attempt snapshot.
            'compact_response' => ['sometimes', 'boolean'],
        ];
    }
}
