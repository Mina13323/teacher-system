<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Support\ExamWindowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CreateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        return $this->user()->can('create', \App\Models\Exam::class)
            && $this->user()->can('update', $course);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'pass_percentage' => ['nullable', 'integer', 'between:0,100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'show_result_immediately' => ['nullable', 'boolean'],
            // Optional official window. Timestamps are interpreted in the
            // application timezone (UTC); clients should send ISO-8601 with an
            // explicit offset. The browser's local timezone never authorizes.
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Reject ambiguous partial windows and inverted ordering. A window must be
     * both-or-neither so an exam can never end up in an undefined timing state.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            ExamWindowRules::validate($this->all(), $validator);
        });
    }
}
