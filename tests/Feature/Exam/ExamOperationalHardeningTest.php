<?php

namespace Tests\Feature\Exam;

use App\Enums\ExamAttemptStatus;
use App\Enums\ExamMakeUpAssignmentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamMakeUpAssignment;
use App\Models\User;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/** Regression coverage for administrative attempt and make-up operations. */
class ExamOperationalHardeningTest extends ApiTestCase
{
    use InteractsWithExams;

    private function setupExam(array $examAttributes = [], bool $requiredExplanation = false): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, array_merge([
            'status' => 'published',
            'duration_minutes' => 30,
            'max_attempts' => 3,
            'shuffle_questions' => false,
            'shuffle_options' => false,
            'show_result_immediately' => false,
        ], $examAttributes));
        $question = $this->addSingleChoiceQuestion($exam, [
            'question_text' => 'Choose the correct answer.',
            'explanation_enabled' => $requiredExplanation,
            'explanation_required' => $requiredExplanation,
        ]);

        return [$teacher, $course, $exam, $question];
    }

    private function enroll(User $student, $course): void
    {
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/courses/{$course->id}/enroll")
            ->assertStatus(201);
    }

    private function start(User $student, Exam $exam): ExamAttempt
    {
        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201);

        return ExamAttempt::findOrFail($response->json('data.id'));
    }

    private function answerCorrectly(User $student, ExamAttempt $attempt, int $questionId, ?string $explanation = null): void
    {
        $attemptQuestion = $attempt->attemptQuestions()->where('question_id', $questionId)->firstOrFail();
        $correctOption = $attemptQuestion->attemptOptions()->where('is_correct', true)->firstOrFail();
        $payload = [
            'question_id' => $questionId,
            'option_id' => $correctOption->option_id,
        ];
        if ($explanation !== null) {
            $payload['explanation'] = $explanation;
        }

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", $payload)
            ->assertStatus(200);
    }

    public function test_start_requires_rules_acknowledgement_and_persists_it_without_resetting_a_resumed_attempt(): void
    {
        [, $course, $exam] = $this->setupExam();
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.integrity_rules.violation_warning_threshold', config('integrity.warning_threshold'));

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rules_acknowledged');
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rules_acknowledged');

        $this->assertDatabaseCount('exam_attempts', 0);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.already_open', false);
        $attempt = ExamAttempt::findOrFail($first->json('data.id'));
        $originalStartedAt = $attempt->started_at->toISOString();
        $originalDeadline = $attempt->expires_at->toISOString();
        $acknowledgedAt = $attempt->rules_acknowledged_at->toISOString();

        $resumed = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.already_open', true);

        $this->assertSame($originalStartedAt, $resumed->json('data.started_at'));
        $this->assertSame($originalDeadline, $resumed->json('data.expires_at'));
        $this->assertSame($acknowledgedAt, $attempt->fresh()->rules_acknowledged_at->toISOString());
        $this->assertSame(1, ExamAttempt::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->count());
    }

    public function test_compact_start_response_skips_the_duplicate_snapshot_payload_and_resumes_safely(): void
    {
        [, $course, $exam] = $this->setupExam();
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);

        $first = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", [
                'rules_acknowledged' => true,
                'compact_response' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', ExamAttemptStatus::InProgress->value)
            ->assertJsonPath('data.already_open', false)
            ->assertJsonMissingPath('data.questions');

        $attempt = ExamAttempt::findOrFail($first->json('data.id'));
        $originalStartedAt = $attempt->started_at->toISOString();
        $originalDeadline = $attempt->expires_at->toISOString();

        $resumed = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", [
                'rules_acknowledged' => true,
                'compact_response' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.already_open', true)
            ->assertJsonMissingPath('data.questions');

        $this->assertSame($originalStartedAt, $resumed->json('data.started_at'));
        $this->assertSame($originalDeadline, $resumed->json('data.expires_at'));
        $this->assertSame(1, ExamAttempt::query()->where('exam_id', $exam->id)->where('student_id', $student->id)->count());
    }

    public function test_full_start_response_matches_the_attempt_snapshot_the_exam_screen_would_read(): void
    {
        [, $course, $exam] = $this->setupExam();
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);

        // The exam screen uses this response instead of reading the attempt
        // again, so it must carry the same snapshot as GET /attempts/{id}.
        $started = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.status', ExamAttemptStatus::InProgress->value)
            ->assertJsonPath('data.already_open', false);

        $read = $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/student/attempts/'.$started->json('data.id'))
            ->assertOk();

        $snapshot = $started->json('data');
        unset($snapshot['already_open']);

        $this->assertNotEmpty($snapshot['questions']);
        $this->assertArrayHasKey('integrity_rules', $snapshot);
        $this->assertEquals($read->json('data'), $snapshot);
    }

    public function test_exam_detail_returns_only_the_students_own_attempt_id_for_resume(): void
    {
        [, $course, $exam] = $this->setupExam();
        $student = $this->createUserWithRole(UserRole::Student);
        $otherStudent = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);
        $this->enroll($otherStudent, $course);

        $attempt = $this->start($student, $exam);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.my_attempts.0.id', $attempt->id)
            ->assertJsonPath('data.my_attempts.0.status', ExamAttemptStatus::InProgress->value);

        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.my_attempts');
    }

    public function test_saved_answer_response_keeps_frozen_integrity_rules_for_the_student_client(): void
    {
        [, $course, $exam, $question] = $this->setupExam();
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);
        $attempt = $this->start($student, $exam);
        $attemptQuestion = $attempt->attemptQuestions()->where('question_id', $question->id)->firstOrFail();
        $option = $attemptQuestion->attemptOptions()->where('is_correct', true)->firstOrFail();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/answers", [
                'question_id' => $question->id,
                'option_id' => $option->option_id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.integrity_rules.violation_warning_threshold', config('integrity.warning_threshold'));
    }

    public function test_required_mcq_explanation_is_separate_from_objective_scoring_and_visible_to_staff(): void
    {
        [$teacher, $course, $exam, $question] = $this->setupExam(['max_attempts' => 1], true);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enroll($student, $course);
        $attempt = $this->start($student, $exam);

        $this->answerCorrectly($student, $attempt, $question->id);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors("answers.{$question->id}.explanation");

        $this->assertSame(ExamAttemptStatus::InProgress, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->score);

        $explanation = 'I selected this option because the evidence in the question supports it.';
        $this->answerCorrectly($student, $attempt->fresh(), $question->id, $explanation);
        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$attempt->id}/submit")
            ->assertStatus(200);

        $this->assertSame(1, $attempt->fresh()->score, 'The explanation must not change MCQ scoring.');
        $this->assertDatabaseHas('exam_answers', [
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'answer_text' => null,
            'explanation' => $explanation,
            'is_correct' => true,
            'points_earned' => 1,
        ]);

        $teacherResponse = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher/attempts/{$attempt->id}")
            ->assertStatus(200);

        $this->assertSame($explanation, $teacherResponse->json('data.questions.0.explanation'));
        $this->assertSame($explanation, $teacherResponse->json('data.answers.0.explanation'));
        $this->assertSame(1, $teacherResponse->json('data.score'));
    }

    public function test_make_up_assignment_is_individual_unique_audited_and_consumed_once(): void
    {
        [$teacher, $course, $exam] = $this->setupExam(['max_attempts' => 1]);
        $approvedStudent = $this->createUserWithRole(UserRole::Student, ['name' => 'Approved Student']);
        $otherStudent = $this->createUserWithRole(UserRole::Student, ['name' => 'Other Student']);
        $this->enroll($approvedStudent, $course);
        $this->enroll($otherStudent, $course);

        $historicalAttempt = $this->start($approvedStudent, $exam);
        $this->actingAs($approvedStudent, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$historicalAttempt->id}/submit")
            ->assertStatus(200);
        $historicalAttempt->refresh();
        $historicalStartedAt = $historicalAttempt->started_at->toISOString();
        $historicalScore = $historicalAttempt->score;

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/make-up-assignments", [
                'student_ids' => [$approvedStudent->id],
                'reason' => 'Documented illness',
            ])
            ->assertStatus(201);

        $assignment = ExamMakeUpAssignment::query()->where('exam_id', $exam->id)->sole();
        $this->assertSame(ExamMakeUpAssignmentStatus::Assigned, $assignment->status);
        $this->assertSame($approvedStudent->id, $assignment->student_id);
        $this->assertNull($assignment->attempt_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'exam.makeup.assign',
            'target_id' => $assignment->id,
            'actor_id' => $teacher->id,
        ]);

        $this->actingAs($approvedStudent, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.make_up_available', true);
        $this->actingAs($otherStudent, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.make_up_available', false);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/make-up-assignments", [
                'student_ids' => [$approvedStudent->id],
                'reason' => 'Duplicate should be rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_ids');
        $this->assertSame(1, ExamMakeUpAssignment::query()->where('exam_id', $exam->id)->count());

        $secondAttemptResponse = $this->actingAs($approvedStudent, 'sanctum')
            ->postJson("/api/v1/student/exams/{$exam->id}/start", ['rules_acknowledged' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.attempt_number', 2);
        $secondAttempt = ExamAttempt::findOrFail($secondAttemptResponse->json('data.id'));

        $assignment->refresh();
        $this->assertSame(ExamMakeUpAssignmentStatus::Used, $assignment->status);
        $this->assertSame($secondAttempt->id, $assignment->attempt_id);
        $this->assertNotNull($assignment->used_at);
        $this->assertSame(2, $secondAttempt->attempt_number);
        $this->assertSame($historicalStartedAt, $historicalAttempt->fresh()->started_at->toISOString());
        $this->assertSame($historicalScore, $historicalAttempt->fresh()->score);

        $this->actingAs($approvedStudent, 'sanctum')
            ->getJson("/api/v1/student/exams/{$exam->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.make_up_available', false);
    }

    public function test_bulk_attempt_deletion_requires_confirmation_is_scoped_soft_deleted_and_audited(): void
    {
        [$teacher, $course, $exam, $question] = $this->setupExam();
        $completedStudent = $this->createUserWithRole(UserRole::Student);
        $activeStudent = $this->createUserWithRole(UserRole::Student);
        $this->enroll($completedStudent, $course);
        $this->enroll($activeStudent, $course);

        $completedAttempt = $this->start($completedStudent, $exam);
        $this->answerCorrectly($completedStudent, $completedAttempt, $question->id);
        $this->actingAs($completedStudent, 'sanctum')
            ->postJson("/api/v1/student/attempts/{$completedAttempt->id}/submit")
            ->assertStatus(200);
        $answer = ExamAnswer::query()->where('attempt_id', $completedAttempt->id)->sole();

        $activeAttempt = $this->start($activeStudent, $exam);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/attempts/bulk-delete", [
                'attempt_ids' => [$completedAttempt->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmed');

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/attempts/bulk-delete", [
                'attempt_ids' => [$completedAttempt->id, $activeAttempt->id],
                'confirmed' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attempt_ids');
        $this->assertNotSoftDeleted('exam_attempts', ['id' => $completedAttempt->id]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/attempts/bulk-delete", [
                'attempt_ids' => [$completedAttempt->id],
                'confirmed' => true,
                'reason' => 'Duplicate test record',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.deleted_ids.0', $completedAttempt->id);

        $this->assertSoftDeleted('exam_attempts', ['id' => $completedAttempt->id]);
        $this->assertDatabaseHas('exam_attempts', [
            'id' => $completedAttempt->id,
            'deleted_by' => $teacher->id,
        ]);
        $this->assertDatabaseHas('exam_answers', [
            'id' => $answer->id,
            'attempt_id' => $completedAttempt->id,
        ]);
        $this->assertNotSoftDeleted('exam_attempts', ['id' => $activeAttempt->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'exam.attempt.delete',
            'target_id' => $completedAttempt->id,
            'actor_id' => $teacher->id,
        ]);

        $otherTeacher = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($otherTeacher, 'sanctum')
            ->postJson("/api/v1/teacher/exams/{$exam->id}/attempts/bulk-delete", [
                'attempt_ids' => [$activeAttempt->id],
                'confirmed' => true,
            ])
            ->assertStatus(403);
    }
}
