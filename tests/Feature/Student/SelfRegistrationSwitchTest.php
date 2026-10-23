<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

class SelfRegistrationSwitchTest extends ApiTestCase
{
    public function test_self_registration_is_unavailable_when_switched_off(): void
    {
        config(['app.student_self_registration' => false]);
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/teacher/student-registration-link')->assertNotFound();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher/student-registration-link/rotate')->assertNotFound();
        $this->getJson('/api/v1/public/student-registration/anything')->assertNotFound();
        $this->postJson('/api/v1/public/student-registration/anything', ['name' => 'X'])->assertNotFound();
    }

    public function test_it_is_available_again_when_switched_on(): void
    {
        config(['app.student_self_registration' => true]);
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/teacher/student-registration-link')->assertOk();
    }
}
