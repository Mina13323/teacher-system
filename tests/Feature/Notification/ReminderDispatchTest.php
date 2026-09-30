<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Assignment;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\Exam;
use App\Models\User;
use App\Notifications\ScheduledReminderNotification;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P2 — Scheduled reminders: idempotent (per recipient+event), preference and
 * quiet-hour aware, retried rather than dropped, covering exam open/close,
 * assignment deadlines and competition endings.
 */
class ReminderDispatchTest extends ApiTestCase
{
    use InteractsWithExams;

    private function enrolledStudent($teacher, $course, array $userAttrs = []): User
    {
        $student = $this->createUserWithRole(UserRole::Student, $userAttrs);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")->assertStatus(201);

        return $student;
    }

    public function test_exam_opening_reminder_is_sent_once_per_student(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(30),
        ]);
        $student = $this->enrolledStudent($teacher, $course);

        // REAL database notifications: the command dedupes against the
        // notifications table, so idempotency can only be proven end-to-end.
        $this->artisan('reminders:dispatch')->assertExitCode(0);

        $rows = \Illuminate\Notifications\DatabaseNotification::query()
            ->where('notifiable_id', $student->id)
            ->where('type', ScheduledReminderNotification::class)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('exam_opening', $rows->first()->data['kind']);

        // Second run: idempotent — nothing new.
        $this->artisan('reminders:dispatch')->assertExitCode(0);
        $this->assertSame(1, \Illuminate\Notifications\DatabaseNotification::query()
            ->where('notifiable_id', $student->id)
            ->where('type', ScheduledReminderNotification::class)
            ->count());
    }

    public function test_exam_closing_assignment_due_and_competition_ending_fire(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHours(3),
        ]);
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'title' => 'Due soon',
            'points' => 10,
            'due_at' => now()->addHours(5),
            'is_published' => true,
        ]);
        $competition = Competition::create([
            'course_id' => $course->id,
            'exam_id' => $exam->id,
            'title' => 'Ending Cup',
            'status' => \App\Enums\CompetitionStatus::Published->value,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addHours(2),
            'scoring_type' => \App\Enums\CompetitionScoringType::HighestScore->value,
            'ranking_type' => \App\Enums\CompetitionRankingType::ScoreDesc->value,
            'max_participants' => 50,
        ]);
        $student = $this->enrolledStudent($teacher, $course);
        CompetitionParticipant::create([
            'competition_id' => $competition->id,
            'student_id' => $student->id,
            'joined_at' => now(),
            'status' => \App\Enums\CompetitionParticipantStatus::Registered->value,
        ]);

        Notification::fake();
        $this->artisan('reminders:dispatch')->assertExitCode(0);

        $kinds = Notification::sent($student, ScheduledReminderNotification::class)
            ->map(fn ($n) => $n->kind)->all();

        $this->assertContains('exam_closing', $kinds);
        $this->assertContains('assignment_due', $kinds);
        $this->assertContains('competition_ending', $kinds);
    }

    public function test_preferences_and_quiet_hours_suppress_but_do_not_drop(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(30),
        ]);

        $optedOut = $this->enrolledStudent($teacher, $course);
        $optedOut->forceFill(['notification_preferences' => ['exam_reminders' => false]])->save();

        $quiet = $this->enrolledStudent($teacher, $course);
        $quiet->forceFill(['notification_preferences' => [
            'quiet_hours' => ['start' => '00:00', 'end' => '23:59'],
        ]])->save();

        $normal = $this->enrolledStudent($teacher, $course);

        Notification::fake();
        $this->artisan('reminders:dispatch')->assertExitCode(0);

        Notification::assertNotSentTo($optedOut, ScheduledReminderNotification::class);
        Notification::assertNotSentTo($quiet, ScheduledReminderNotification::class);
        Notification::assertSentTo($normal, ScheduledReminderNotification::class);

        // Quiet hours delay, never drop: after the quiet window ends, the very
        // same reminder arrives on the next run (no duplicate afterwards).
        $quiet->forceFill(['notification_preferences' => []])->save();
        $this->artisan('reminders:dispatch')->assertExitCode(0);

        Notification::assertSentTo($quiet, ScheduledReminderNotification::class, function ($n) {
            return $n->kind === 'exam_opening';
        });
        // opted-out student still receives nothing.
        Notification::assertNotSentTo($optedOut, ScheduledReminderNotification::class);
    }

    public function test_non_relevant_and_distant_events_are_not_reminded(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);

        // Exam opens in 3 days — outside the 24h window.
        $this->makeExam($teacher, $course, [
            'status' => 'published',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
        ]);
        // Draft exam opening soon — never announced.
        $this->makeExam($teacher, $course, [
            'status' => 'draft',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(30),
        ]);
        $student = $this->enrolledStudent($teacher, $course);

        Notification::fake();
        $this->artisan('reminders:dispatch')->assertExitCode(0);

        Notification::assertNotSentTo($student, ScheduledReminderNotification::class);
    }

    public function test_notification_preferences_endpoint_roundtrip(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);

        $show = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/notification-preferences')
            ->assertStatus(200);
        $this->assertTrue($show->json('data.exam_reminders'));

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/v1/student/notification-preferences', [
                'exam_reminders' => false,
                'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
            ])->assertStatus(200)
            ->assertJsonPath('data.exam_reminders', false)
            ->assertJsonPath('data.quiet_hours.start', '22:00');

        $student->refresh();
        $this->assertFalse($student->notification_preferences['exam_reminders']);
    }
}
