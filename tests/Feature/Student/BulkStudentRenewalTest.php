<?php

namespace Tests\Feature\Student;

use App\Enums\AcademicYear;
use App\Enums\StudentAccessStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ApiTestCase;

class BulkStudentRenewalTest extends ApiTestCase
{
    private function student(User $teacher, string $name, StudentAccessStatus $status, $expiresAt): User
    {
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students', ['name' => $name, 'academic_year' => AcademicYear::Secondary2->value])
            ->assertStatus(201);

        $student = User::where('name', $name)->firstOrFail();
        $student->latestAccessPeriod()->update(['status' => $status, 'expires_at' => $expiresAt]);

        return $student->fresh();
    }

    public function test_renews_every_due_student_for_the_chosen_months_and_nobody_else(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $dueA = $this->student($teacher, 'Due Alpha', StudentAccessStatus::Due, now()->subDay());
        $dueB = $this->student($teacher, 'Due Beta', StudentAccessStatus::Active, now()->subDays(3)); // expired, cron not run yet
        $active = $this->student($teacher, 'Still Active', StudentAccessStatus::Active, now()->addDays(10));
        $suspended = $this->student($teacher, 'Suspended One', StudentAccessStatus::Suspended, now()->subDay());
        $suspended->forceFill(['is_active' => false])->save();

        // Another teacher's due student is out of scope.
        $other = $this->createUserWithRole(UserRole::Teacher);
        $foreign = $this->student($other, 'Foreign Due', StudentAccessStatus::Due, now()->subDay());

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students/renew-due/count')
            ->assertOk()
            ->assertJsonPath('data.due_count', 2);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/renew-due', ['months' => 3])
            ->assertOk()
            ->assertJsonPath('data.renewed_count', 2)
            ->assertJsonPath('data.months', 3);

        foreach ([$dueA, $dueB] as $student) {
            $student->refresh();
            $this->assertSame(StudentAccessStatus::Active->value, $student->accessStatus());
            $this->assertTrue($student->latestAccessPeriod->expires_at->between(now()->addMonths(3)->subMinute(), now()->addMonths(3)->addMinute()));
        }

        // Untouched: still-active (no new period), suspended (stays suspended), other teacher's student.
        $this->assertSame(1, $active->accessPeriods()->count());
        $this->assertSame(StudentAccessStatus::Suspended->value, $suspended->fresh()->accessStatus());
        $this->assertSame(StudentAccessStatus::Due->value, $foreign->fresh()->accessStatus());

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students/renew-due/count')
            ->assertJsonPath('data.due_count', 0);
    }

    public function test_months_are_validated_and_students_cannot_use_it(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/renew-due', ['months' => 0])->assertUnprocessable();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/renew-due', ['months' => 13])->assertUnprocessable();

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/teacher/students/renew-due', ['months' => 1])->assertForbidden();
    }

    public function test_database_work_per_renewed_student_is_one_insert(): void
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        foreach (range(1, 6) as $i) {
            $this->student($teacher, "Bulk Student $i", StudentAccessStatus::Due, now()->subDay());
        }

        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/renew-due', ['months' => 2])->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame(6, $queries->filter(fn ($q) => str_starts_with($q, 'insert into "student_access_periods"') || str_starts_with($q, 'insert into `student_access_periods`'))->count());
        // No per-student notification scans or reloads: the whole run stays small.
        $this->assertLessThan(40, $queries->count(), $queries->implode("\n"));
    }
}
