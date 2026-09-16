<?php

namespace App\Http\Requests;

use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves an existing exam's structure as a reusable, teacher-owned template.
 *
 * The exam is route-bound, so authorization is delegated to the exam policy:
 * a teacher may only turn an exam they manage into a template.
 */
class StoreExamTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
