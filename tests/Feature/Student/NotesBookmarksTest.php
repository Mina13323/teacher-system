<?php

namespace Tests\Feature\Student;

use App\Enums\UserRole;
use Tests\Feature\ApiTestCase;

/**
 * PHASE 4 §32/§33 — Private notes and bookmarks: owner-only (IDOR), timestamp
 * association, idempotent bookmarking.
 */
class NotesBookmarksTest extends ApiTestCase
{
    public function test_notes_are_private_and_owner_only(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $other = $this->createUserWithRole(UserRole::Student);

        $create = $this->actingAs($student, 'sanctum')->postJson('/api/v1/student/notes', [
            'body' => 'remember the trade winds',
            'position_seconds' => 95,
        ])->assertStatus(201);
        $noteId = $create->json('data.id');

        // Owner sees it; nobody else can read, edit or delete it.
        $this->actingAs($student, 'sanctum')->getJson('/api/v1/student/notes')->assertStatus(200);
        $this->actingAs($other, 'sanctum')->putJson("/api/v1/student/notes/{$noteId}", ['body' => 'x'])->assertStatus(403);
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/student/notes/{$noteId}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/student/notes')->assertStatus(200);

        // Even course staff have no notes surface (policy: owner-only).
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/student/notes')->assertStatus(403);

        $this->actingAs($student, 'sanctum')->putJson("/api/v1/student/notes/{$noteId}", ['body' => 'updated'])->assertStatus(200);
    }

    public function test_bookmarks_are_idempotent_and_owner_only(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $payload = ['lesson_id' => null, 'video_id' => null, 'position_seconds' => 30, 'label' => 'key formula'];
        // needs a real lesson — create minimal content
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $unit = $this->createUnit($course);
        $lesson = \App\Models\Lesson::factory()->create(['unit_id' => $unit->id, 'is_published' => true]);
        $payload['lesson_id'] = $lesson->id;

        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student/bookmarks', $payload)->assertStatus(201);
        $this->actingAs($student, 'sanctum')->postJson('/api/v1/student/bookmarks', $payload)->assertStatus(201);

        $list = $this->actingAs($student, 'sanctum')->getJson('/api/v1/student/bookmarks')->assertStatus(200);
        $this->assertCount(1, $list->json('data'), 'Same place bookmarks once');

        $other = $this->createUserWithRole(UserRole::Student);
        $bookmarkId = $list->json('data.0.id');
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/student/bookmarks/{$bookmarkId}")->assertStatus(403);
        $this->actingAs($student, 'sanctum')->deleteJson("/api/v1/student/bookmarks/{$bookmarkId}")->assertStatus(200);
    }

    public function test_bookmark_requires_a_reference(): void
    {
        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/v1/student/bookmarks', ['label' => 'nothing'])->assertStatus(422);
    }
}
