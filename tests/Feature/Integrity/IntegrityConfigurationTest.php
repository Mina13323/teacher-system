<?php

namespace Tests\Feature\Integrity;

use App\Enums\UserRole;
use App\Models\Exam;
use App\Models\ExamAttemptIntegritySetting;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityConfigurationTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_teacher_can_configure_integrity_settings(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/integrity", [
                'fullscreen_required' => true,
                'prevent_copy' => true,
                'detect_tab_switch' => false,
            ])->assertStatus(200)
            ->assertJsonPath('data.fullscreen_required', true)
            ->assertJsonPath('data.prevent_copy', true)
            ->assertJsonPath('data.detect_tab_switch', false);

        $this->assertDatabaseHas('exam_integrity_settings', [
            'exam_id' => $exam->id,
            'fullscreen_required' => true,
            'prevent_copy' => true,
            'detect_tab_switch' => false,
        ]);
    }

    public function test_teacher_cannot_configure_another_teachers_exam(): void
    {
        $owner = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($owner, ['status' => 'published']);
        $exam = $this->makePublishedExam($owner, $course);
        $other = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($other, 'sanctum')
            ->putJson("/api/v1/teacher/exams/{$exam->id}/integrity", ['prevent_copy' => true])
            ->assertStatus(403);
    }

    public function test_settings_are_frozen_into_new_attempts(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['fullscreen_required' => true, 'prevent_copy' => true, 'detect_tab_switch' => false]
        );

        $this->assertDatabaseHas('exam_attempt_integrity_settings', [
            'attempt_id' => $attempt->id,
            'fullscreen_required' => true,
            'prevent_copy' => true,
            'detect_tab_switch' => false,
        ]);
    }

    public function test_changing_live_settings_does_not_alter_existing_attempt(): void
    {
        [$student, , $exam, $teacher, $attempt] = $this->enrolledStudentWithStartedAttempt(
            [],
            ['prevent_copy' => false, 'detect_tab_switch' => true]
        );

        // Teacher changes the live exam integrity settings.
        $this->configureIntegrity($teacher, $exam, [
            'prevent_copy' => true,
            'detect_tab_switch' => false,
        ]);

        // The attempt's frozen settings remain the original values.
        $this->assertDatabaseHas('exam_attempt_integrity_settings', [
            'attempt_id' => $attempt->id,
            'prevent_copy' => false,
            'detect_tab_switch' => true,
        ]);
    }

    public function test_integrity_settings_defaults_are_applied_when_absent(): void
    {
        [$student, , $exam, , $attempt] = $this->enrolledStudentWithStartedAttempt();

        // Defaults: tab/blur detection on, prevention flags off.
        $this->assertDatabaseHas('exam_attempt_integrity_settings', [
            'attempt_id' => $attempt->id,
            'detect_tab_switch' => true,
            'detect_window_blur' => true,
            'prevent_copy' => false,
            'prevent_paste' => false,
        ]);
    }

    public function test_getting_settings_returns_defaults_when_unconfigured(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makePublishedExam($teacher, $course);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/exams/{$exam->id}/integrity")
            ->assertStatus(200)
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.settings.detect_tab_switch', true);
    }
}
