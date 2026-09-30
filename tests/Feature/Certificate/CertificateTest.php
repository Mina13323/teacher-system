<?php

namespace Tests\Feature\Certificate;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Tests\Feature\ApiTestCase;

/**
 * P2 — Certificates: completion eligibility, unique verification code,
 * idempotent issuance, public minimum-disclosure verification.
 */
class CertificateTest extends ApiTestCase
{
    private function courseWithLessons(int $publishedLessons = 2, int $unpublished = 1): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);

        $lessons = [];
        for ($i = 0; $i < $publishedLessons; $i++) {
            $lessons[] = Lesson::factory()->create([
                'unit_id' => $unit->id,
                'is_published' => true,
                'content' => "Lesson {$i}",
            ]);
        }
        for ($i = 0; $i < $unpublished; $i++) {
            $lessons[] = Lesson::factory()->create([
                'unit_id' => $unit->id,
                'is_published' => false,
            ]);
        }

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return [$teacher, $course, $lessons, $student];
    }

    private function completeLesson($student, $lesson): void
    {
        LessonProgress::create([
            'student_id' => $student->id,
            'lesson_id' => $lesson->id,
            'completed' => true,
            'progress_percentage' => 100,
            'completed_at' => now(),
        ]);
    }

    public function test_certificate_requires_every_published_lesson_complete(): void
    {
        [, $course, $lessons, $student] = $this->courseWithLessons(2, 1);

        // Only one of two published lessons done — unpublished lesson ignored.
        $this->completeLesson($student, $lessons[0]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(422);

        $this->completeLesson($student, $lessons[1]);

        $res = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(201);

        $this->assertNotEmpty($res->json('data.code'));
        $this->assertSame($course->id, $res->json('data.course.id'));
    }

    public function test_issuance_is_idempotent_and_code_never_regenerated(): void
    {
        [, $course, $lessons, $student] = $this->courseWithLessons(1, 0);
        $this->completeLesson($student, $lessons[0]);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(201);
        $code = $first->json('data.code');

        $second = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(200);

        $this->assertSame($code, $second->json('data.code'));
        $this->assertSame(1, Certificate::where('course_id', $course->id)->count());
    }

    public function test_public_verification_reveals_minimum_only(): void
    {
        [, $course, $lessons, $student] = $this->courseWithLessons(1, 0);
        $this->completeLesson($student, $lessons[0]);

        $issued = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(201);
        $code = $issued->json('data.code');

        // Anonymous verification.
        $res = $this->getJson("/api/v1/public/certificates/{$code}")->assertStatus(200);
        $this->assertTrue($res->json('data.valid'));
        $this->assertSame($student->name, $res->json('data.student_name'));
        $this->assertSame($course->title, $res->json('data.course_title'));

        // Minimum disclosure: nothing beyond name/course/date.
        $keys = array_keys($res->json('data'));
        $this->assertEmpty(array_diff($keys, ['valid', 'code', 'student_name', 'course_title', 'issued_at']));

        // Unknown codes are a clean 404 (no enumeration).
        $this->getJson('/api/v1/public/certificates/CERT-DOESNOTEXIST')->assertStatus(404);
    }

    public function test_students_see_only_their_own_certificates(): void
    {
        [, $course, $lessons, $student] = $this->courseWithLessons(1, 0);
        $this->completeLesson($student, $lessons[0]);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(201);

        $other = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/student/certificates')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(404);
    }

    public function test_non_enrolled_student_cannot_issue(): void
    {
        [, $course, $lessons, $student] = $this->courseWithLessons(1, 0);
        $this->completeLesson($student, $lessons[0]);

        $stranger = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(403);
    }

    public function test_empty_course_never_issues_certificates(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/certificate")
            ->assertStatus(422);
    }
}
