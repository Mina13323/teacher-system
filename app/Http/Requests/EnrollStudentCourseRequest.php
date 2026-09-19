<?php

namespace App\Http\Requests;

use App\Enums\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;

class EnrollStudentCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageEnrollments', $this->route('course'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'student_id' => ['nullable', 'required_without:academic_year', 'integer', 'exists:users,id'],
            'academic_year' => ['nullable', 'required_without:student_id', 'string', 'in:'.implode(',', array_column(AcademicYear::options(), 'value'))],
        ];
    }
}
