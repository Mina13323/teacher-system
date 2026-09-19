<?php

namespace Tests\Feature\Enrollment;

use App\Enums\UserRole;
use App\Models\Enrollment;
use Tests\Feature\ApiTestCase;

class AcademicYearEnrollmentTest extends ApiTestCase
{
    public function test_teacher_can_enroll_their_active_academic_year_without_touching_other_teachers_students(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher);
        $included = $this->createUserWithRole(UserRole::Student, ['created_by' => $teacher->id, 'academic_year' => 'secondary_2']);
        $inactive = $this->createUserWithRole(UserRole::Student, ['created_by' => $teacher->id, 'academic_year' => 'secondary_2', 'is_active' => false]);
        $outside = $this->createUserWithRole(UserRole::Student, ['created_by' => $otherTeacher->id, 'academic_year' => 'secondary_2']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/courses/{$course->id}/students", ['academic_year' => 'secondary_2'])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 1);

        $this->assertTrue(Enrollment::where('course_id', $course->id)->where('student_id', $included->id)->exists());
        $this->assertFalse(Enrollment::where('course_id', $course->id)->where('student_id', $inactive->id)->exists());
        $this->assertFalse(Enrollment::where('course_id', $course->id)->where('student_id', $outside->id)->exists());
    }
}
