<?php

namespace App\Http\Requests;

use App\Enums\QuestionType;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validates a whole question paper submitted from the bulk authoring screen.
 *
 * Question text and option text are deliberately *nullable* here: a template can
 * hand the teacher fifty blank questions, and they must be able to save part-way
 * through and come back. Completeness is enforced where it matters — at publish
 * time, by PublishExamAction — not on every intermediate draft save.
 */
class SyncExamQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Exam $exam */
        $exam = $this->route('exam');

        return $this->user()->can('update', $exam);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'questions' => ['required', 'array', 'min:1', 'max:200'],

            // An id means "update this row"; absent means "create a new one".
            // Ownership against the route-bound exam is re-checked in the action,
            // so a valid id from someone else's paper is rejected there.
            'questions.*.id' => ['nullable', 'integer', 'exists:questions,id'],
            'questions.*.question_text' => ['nullable', 'string', 'max:20000'],
            'questions.*.type' => ['required', new Enum(QuestionType::class)],
            'questions.*.points' => ['required', 'integer', 'min:1', 'max:1000'],
            'questions.*.reference_answer' => ['nullable', 'string', 'max:5000'],

            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*.id' => ['nullable', 'integer', 'exists:options,id'],
            'questions.*.options.*.option_text' => ['nullable', 'string', 'max:2000'],
            'questions.*.options.*.is_correct' => ['nullable', 'boolean'],
        ];
    }
}
