<?php

namespace Tests\Feature\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\UserRole;
use App\Models\Competition;
use App\Models\Exam;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Competition\Concerns\InteractsWithCompetitions;

class CompetitionRegistrationTest extends ApiTestCase
{
    use InteractsWithCompetitions;

    private function setup()
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = Exam::factory()->create(['course_id' => $course->id, 'created_by' => $teacher->id]);

        return [$teacher, $course, $exam];
    }

    public function test_student_can_join_active_competition(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->joinCompetition($student, $competition)
            ->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.is_joined', true);

        $this->assertDatabaseHas('competition_participants', [
            'competition_id' => $competition->id,
            'student_id' => $student->id,
            'status' => 'registered',
        ]);
    }

    public function test_student_cannot_join_before_the_window_opens(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeCompetition($teacher, $exam, [
            'status' => CompetitionStatus::Published->value,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(7),
        ]);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->joinCompetition($student, $competition)->assertStatus(403);
    }

    public function test_student_cannot_join_an_ended_competition(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeEndedCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->joinCompetition($student, $competition)->assertStatus(403);
    }

    public function test_student_must_be_enrolled_in_the_course_to_join(): void
    {
        [$teacher, , $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);

        $this->joinCompetition($student, $competition)->assertStatus(403);
    }

    public function test_student_cannot_join_the_same_competition_twice(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam);
        $student = $this->createUserWithRole(UserRole::Student);
        $this->enrollStudent($student, $course);

        $this->joinCompetition($student, $competition)->assertStatus(201);
        $this->joinCompetition($student, $competition)->assertStatus(409);

        $this->assertDatabaseCount('competition_participants', 1);
    }

    public function test_capacity_is_enforced_server_side(): void
    {
        [$teacher, $course, $exam] = $this->setup();
        $competition = $this->makeActiveCompetition($teacher, $exam, ['max_participants' => 2]);

        $students = collect(range(1, 3))->map(fn () => $this->createUserWithRole(UserRole::Student));

        foreach ($students as $student) {
            $this->enrollStudent($student, $course);
        }

        $this->joinCompetition($students[0], $competition)->assertStatus(201);
        $this->joinCompetition($students[1], $competition)->assertStatus(201);
        $this->joinCompetition($students[2], $competition)->assertStatus(409);

        $this->assertDatabaseCount('competition_participants', 2);
    }
}
