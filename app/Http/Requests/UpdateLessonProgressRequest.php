<?php

namespace App\Http\Requests;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $lesson = $this->route('lesson');

        if (! $user->hasRole('student') && ! $user->hasRole('admin')) {
            return false;
        }

        // Only published lessons belonging to an enrolled course may be tracked.
        if (! $lesson->isPublished()) {
            return false;
        }

        $enrolled = Enrollment::query()
            ->where('student_id', $user->getKey())
            ->where('course_id', $lesson->unit->course_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->exists();

        // And the canonical lesson-access rules: the response carries the
        // lesson's content, and completing a roadmap-locked lesson would
        // unlock the next one.
        return $enrolled && $user->can('access', $lesson);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'progress_percentage' => ['required', 'integer', 'between:0,100'],
            'last_position_seconds' => ['nullable', 'integer', 'min:0'],
            'completed' => ['nullable', 'boolean'],
        ];
    }
}
