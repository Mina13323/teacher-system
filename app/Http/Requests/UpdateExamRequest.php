<?php

namespace App\Http\Requests;

use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Unit;
use App\Support\ExamWindowRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'lesson_id' => ['sometimes', 'nullable', 'integer', 'exists:lessons,id'],
            'unit_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'unit_ids.*' => ['integer', 'distinct', 'exists:units,id'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'pass_percentage' => ['sometimes', 'integer', 'between:0,100'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'shuffle_options' => ['sometimes', 'boolean'],
            'show_result_immediately' => ['sometimes', 'boolean'],
            // Omitting a key keeps its stored value; sending null clears it.
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Validate the window against the *resulting* state, so a partial update
     * that would leave one half of a window set is rejected.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Exam $exam */
            $exam = $this->route('exam');

            ExamWindowRules::validate($this->all(), $validator, [
                'starts_at' => $exam->starts_at?->toIso8601String(),
                'ends_at' => $exam->ends_at?->toIso8601String(),
            ]);

            $lessonId = $this->has('lesson_id') ? $this->input('lesson_id') : $exam->lesson_id;
            $unitIds = $this->has('unit_ids') ? $this->input('unit_ids') : $exam->unit_ids;
            if ($lessonId && ! Lesson::whereKey($lessonId)->whereIn('unit_id', Unit::query()->where('course_id', $exam->course_id)->select('id'))->exists()) {
                $validator->errors()->add('lesson_id', 'The selected lesson does not belong to this course.');
            }
            if ($lessonId && ! empty($unitIds)) {
                $validator->errors()->add('unit_ids', 'Choose a lesson or unit(s), not both.');
            }
            if (! empty($unitIds) && Unit::whereIn('id', $unitIds)->where('course_id', $exam->course_id)->count() !== count($unitIds)) {
                $validator->errors()->add('unit_ids', 'Every selected unit must belong to this course.');
            }
        });
    }
}
