<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Feature\ApiTestCase;

class StudentDuplicatesAndSortingTest extends ApiTestCase
{
    private function makeTeacher(): User
    {
        return $this->createUserWithRole(UserRole::Teacher);
    }

    private function createStudentFor(User $teacher, array $attributes): User
    {
        $student = User::factory()->create(array_merge([
            'created_by' => $teacher->id,
            'is_active' => true,
        ], $attributes));
        $student->assignRole(UserRole::Student->value);
        return $student;
    }

    public function test_teacher_can_list_students_in_alphabetical_order(): void
    {
        $teacher = $this->makeTeacher();

        $this->createStudentFor($teacher, ['name' => 'شروق حسن', 'email' => 'shorouk@example.com']);
        $this->createStudentFor($teacher, ['name' => 'أحمد علي', 'email' => 'ahmed@example.com']);
        $this->createStudentFor($teacher, ['name' => 'Bob Smith', 'email' => 'bob@example.com']);
        $this->createStudentFor($teacher, ['name' => 'Alice Cooper', 'email' => 'alice@example.com']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher/students?sort=name_asc');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->toArray();

        // In standard Unicode ordering: Latin letters (A-Z) come before Arabic letters (أ، ش)
        // Alice Cooper, Bob Smith, أحمد علي, شروق حسن
        $this->assertSame(['Alice Cooper', 'Bob Smith', 'أحمد علي', 'شروق حسن'], $names);
    }

    public function test_teacher_can_filter_duplicate_students_and_see_duplicate_flag(): void
    {
        $teacher = $this->makeTeacher();

        // Duplicate by name
        $dup1 = $this->createStudentFor($teacher, ['name' => 'شروق حسن', 'email' => 'shorouk1@example.com', 'phone' => '+201111111111']);
        $dup2 = $this->createStudentFor($teacher, ['name' => 'شروق حسن', 'email' => 'shorouk2@example.com', 'phone' => '+201111111112']);
        // Unique student
        $unique = $this->createStudentFor($teacher, ['name' => 'محمود علي', 'email' => 'mahmoud@example.com', 'phone' => '+201222222222']);

        // Without filter: all 3 returned, but duplicates have is_duplicate = true
        $resAll = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/teacher/students');
        $resAll->assertOk();
        $items = collect($resAll->json('data'))->keyBy('id');
        $this->assertTrue($items[$dup1->id]['is_duplicate']);
        $this->assertTrue($items[$dup2->id]['is_duplicate']);
        $this->assertFalse($items[$unique->id]['is_duplicate']);

        // With duplicates filter: only dup1 and dup2 returned
        $resDup = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/teacher/students?duplicates=1');
        $resDup->assertOk();
        $dupIds = collect($resDup->json('data'))->pluck('id')->toArray();
        $this->assertContains($dup1->id, $dupIds);
        $this->assertContains($dup2->id, $dupIds);
        $this->assertNotContains($unique->id, $dupIds);
    }

    public function test_teacher_can_delete_a_duplicate_student(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->createStudentFor($teacher, ['name' => 'طالب مكرر', 'email' => 'duplicate@example.com']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/teacher/students/{$student->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $student->id]);
    }

    public function test_teacher_can_batch_delete_duplicate_students(): void
    {
        $teacher = $this->makeTeacher();
        $s1 = $this->createStudentFor($teacher, ['name' => 'طالب مكرر 1', 'email' => 'dup1@example.com']);
        $s2 = $this->createStudentFor($teacher, ['name' => 'طالب مكرر 2', 'email' => 'dup2@example.com']);
        $keeper = $this->createStudentFor($teacher, ['name' => 'طالب أصلي', 'email' => 'original@example.com']);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/teacher/students/batch-delete', [
                'ids' => [$s1->id, $s2->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('users', ['id' => $s1->id]);
        $this->assertDatabaseMissing('users', ['id' => $s2->id]);
        $this->assertDatabaseHas('users', ['id' => $keeper->id]);
    }
}
