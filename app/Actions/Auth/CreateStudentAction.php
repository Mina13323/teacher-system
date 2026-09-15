<?php

namespace App\Actions\Auth;

use App\Enums\AcademicSubject;
use App\Enums\AcademicYear;
use App\Enums\StudentAccessStatus;
use App\Enums\StudentCapabilityPreset;
use App\Enums\UserRole;
use App\Models\StudentAccessPeriod;
use App\Models\User;
use App\Services\StudentCredentialService;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates a student account on behalf of a teacher (or assistant/admin) in a
 * school workflow LMS.
 */
class CreateStudentAction
{
    public function __construct(
        private readonly StudentCredentialService $credentialsService,
    ) {
    }

    public function execute(User $creator, array $data): User
    {
        Role::findOrCreate(UserRole::Student->value);

        $name = trim($data['name']);

        // Determine student code
        $studentCode = $data['student_code'] ?? $this->credentialsService->generateStudentCode();

        // Determine email ({student_code}@student.com template)
        $email = ! empty($data['email'])
            ? mb_strtolower(trim($data['email']))
            : $this->credentialsService->generateEmail($name, $studentCode);

        // Determine academic year
        $academicYear = $data['academic_year'] ?? AcademicYear::Secondary1->value;
        if ($academicYear instanceof AcademicYear) {
            $academicYear = $academicYear->value;
        }

        // Determine academic subject
        $academicSubject = $data['academic_subject'] ?? null;
        if ($academicSubject instanceof AcademicSubject) {
            $academicSubject = $academicSubject->value;
        }

        if ($academicYear === AcademicYear::Secondary3->value) {
            if (! in_array($academicSubject, [AcademicSubject::History->value, AcademicSubject::Geography->value, AcademicSubject::Both->value], true)) {
                $academicSubject = AcademicSubject::Both->value;
            }
        } else {
            $academicSubject = AcademicSubject::General->value;
        }

        // Determine password ({student_code}2026 template)
        $isGeneratedPassword = empty($data['password']);
        $rawPassword = ! $isGeneratedPassword
            ? $data['password']
            : $this->credentialsService->generateTemporaryPassword($studentCode, '2026');

        // Determine access capabilities
        $canLessons = true;
        $canExams = true;
        $canCompetitions = true;

        if (! empty($data['capability_preset'])) {
            $preset = StudentCapabilityPreset::tryFrom(strtoupper($data['capability_preset']));
            if ($preset && $preset !== StudentCapabilityPreset::Custom) {
                $caps = $preset->capabilities();
                if ($caps) {
                    $canLessons = $caps['can_access_lessons'];
                    $canExams = $caps['can_take_exams'];
                    $canCompetitions = $caps['can_join_competitions'];
                }
            }
        }

        if (array_key_exists('can_access_lessons', $data)) {
            $canLessons = (bool) $data['can_access_lessons'];
        }
        if (array_key_exists('can_take_exams', $data)) {
            $canExams = (bool) $data['can_take_exams'];
        }
        if (array_key_exists('can_join_competitions', $data)) {
            $canCompetitions = (bool) $data['can_join_competitions'];
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'student_code' => $studentCode,
            'password' => Hash::make($rawPassword),
            'phone' => $data['phone'] ?? null,
            'bio' => $data['bio'] ?? null,
            'academic_year' => $academicYear,
            'academic_subject' => $academicSubject,
            'can_access_lessons' => $canLessons,
            'can_take_exams' => $canExams,
            'can_join_competitions' => $canCompetitions,
            'is_active' => true,
            'must_change_password' => $isGeneratedPassword,
            'created_by' => $creator->isAssistant()
                ? ($creator->created_by ?: (User::role(UserRole::Teacher->value)->value('id') ?: $creator->getKey()))
                : $creator->getKey(),
            'profile_completed_at' => ($data['phone'] ?? null) !== null ? now() : null,
        ]);

        $user->assignRole(UserRole::Student->value);

        // Provision initial monthly access period
        $startsAt = ! empty($data['access_starts_at']) ? \Carbon\Carbon::parse($data['access_starts_at']) : now();
        $expiresAt = ! empty($data['access_expires_at']) ? \Carbon\Carbon::parse($data['access_expires_at']) : now()->addMonth();

        StudentAccessPeriod::create([
            'student_id' => $user->getKey(),
            'status' => StudentAccessStatus::Active->value,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'amount' => $data['amount'] ?? null,
            'notes' => $data['notes'] ?? 'Initial enrollment access period',
            'approved_by' => $creator->getKey(),
            'approved_at' => now(),
        ]);

        // Expose one-time credentials on model instance for the immediate controller response
        $user->generated_credentials = [
            'student_code' => $studentCode,
            'login' => $email,
            'temporary_password' => $rawPassword,
        ];

        return $user;
    }
}
