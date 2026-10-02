<?php

namespace Tests\Feature\Auth;

use App\Enums\AcademicYear;
use App\Enums\UserRole;
use App\Models\StudentRegistrationLink;
use Illuminate\Support\Str;
use Tests\Feature\ApiTestCase;

class PublicStudentRegistrationTest extends ApiTestCase
{
    public function test_public_registration_link_uses_academic_year_enum_values(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $token = Str::random(48);

        StudentRegistrationLink::create([
            'teacher_id' => $teacher->getKey(),
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
        ]);

        $expected = array_map(
            static fn (AcademicYear $year): string => $year->value,
            AcademicYear::cases(),
        );

        $this->getJson("/api/v1/public/student-registration/{$token}")
            ->assertOk()
            ->assertJsonPath('data.academic_years', $expected);
    }
}
