<?php

namespace Tests\Feature\Integrity;

use Tests\Feature\ApiTestCase;
use Tests\Feature\Integrity\Concerns\InteractsWithIntegrity;

class IntegrityPrivacyTest extends ApiTestCase
{
    use InteractsWithIntegrity;

    public function test_student_attempt_view_does_not_expose_integrity_data(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/attempts/{$attempt->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('risk_score', $response->getContent());
        $this->assertStringNotContainsString('integrity_status', $response->getContent());
        $this->assertStringNotContainsString('risk_points', $response->getContent());
        $this->assertStringNotContainsString('severity', $response->getContent());
        $this->assertStringNotContainsString('reviews', $response->getContent());
    }

    public function test_event_recording_response_does_not_expose_risk_or_severity(): void
    {
        [$student, , , , $attempt] = $this->enrolledStudentWithStartedAttempt();

        $response = $this->postEvent($student, $attempt, ['event_type' => 'TAB_SWITCH']);

        $response->assertStatus(201);
        $this->assertStringNotContainsString('risk_points', $response->getContent());
        $this->assertStringNotContainsString('severity', $response->getContent());
        $this->assertStringNotContainsString('risk_score', $response->getContent());
        $this->assertStringNotContainsString('integrity_status', $response->getContent());
    }

    public function test_student_exam_listing_does_not_expose_integrity_data(): void
    {
        [$student, , $exam] = $this->enrolledStudentWithStartedAttempt();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/exams');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('risk_score', $response->getContent());
        $this->assertStringNotContainsString('integrity_status', $response->getContent());
    }
}
