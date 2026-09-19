<?php

namespace App\Http\Requests;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Unit;
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
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'unit_ids' => ['nullable', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:units,id'],
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

            /** @var Course $course */
            $course = $this->route('course');
            if ($this->filled('lesson_id') && ! Lesson::whereKey($this->integer('lesson_id'))
                ->whereIn('unit_id', Unit::query()->where('course_id', $course->getKey())->select('id'))->exists()) {
                $validator->errors()->add('lesson_id', 'The selected lesson does not belong to this course.');
            }
            if ($this->filled('lesson_id') && $this->filled('unit_ids')) {
                $validator->errors()->add('unit_ids', 'Choose a lesson or unit(s), not both.');
            }
            if ($this->filled('unit_ids') && Unit::whereIn('id', $this->input('unit_ids', []))
                ->where('course_id', $course->getKey())->count() !== count($this->input('unit_ids', []))) {
                $validator->errors()->add('unit_ids', 'Every selected unit must belong to this course.');
            }
        });
    }
}
