<?php

namespace Tests\Feature\Teacher;

use App\Enums\UserRole;
use App\Jobs\GenerateResultExportJob;
use App\Models\ExamAttempt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\ApiTestCase;
use Tests\Feature\Exam\Concerns\InteractsWithExams;

/**
 * P1-G: above 500 rows the export is queued (202 JSON with an export id), the
 * status endpoint reports its state to the requester only, and the file is
 * downloadable only once done. The small-sheet path still streams the file.
 */
class ResultExportQueueTest extends ApiTestCase
{
    use InteractsWithExams;

    private function examWithAttempts(int $count): array
    {
        $teacher = $this->createUserWithRole(UserRole::Teacher);
        $course = $this->createCourse($teacher, ['status' => 'published']);
        $exam = $this->makeExam($teacher, $course, ['status' => 'published']);
        ExamAttempt::factory()->count($count)->create(['exam_id' => $exam->id]);

        return [$teacher, $exam];
    }

    public function test_a_large_export_is_queued_as_json_and_never_streams_a_fake_file(): void
    {
        Queue::fake();
        [$teacher, $exam] = $this->examWithAttempts(501);

        $res = $this->actingAs($teacher, 'sanctum')
            ->get("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")
            ->assertStatus(202)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.rows', 501);
        $exportId = $res->json('data.export_id');
        Queue::assertPushed(GenerateResultExportJob::class);

        // Not processed (no worker): queued, nothing to download.
        $this->actingAs($teacher, 'sanctum')->getJson("/api/v1/teacher/exports/{$exportId}")
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.download_url', null);
        $this->actingAs($teacher, 'sanctum')->get("/api/v1/teacher/exports/{$exportId}/download")->assertStatus(404);

        // Only the requester can follow it.
        $other = $this->createUserWithRole(UserRole::Teacher);
        $this->actingAs($other, 'sanctum')->getJson("/api/v1/teacher/exports/{$exportId}")->assertStatus(403);
        $this->actingAs($other, 'sanctum')->get("/api/v1/teacher/exports/{$exportId}/download")->assertStatus(403);
    }

    public function test_once_processed_the_requester_downloads_the_real_csv(): void
    {
        Storage::fake('local');
        // phpunit runs the queue synchronously, standing in for a worker.
        [$teacher, $exam] = $this->examWithAttempts(501);

        $exportId = $this->actingAs($teacher, 'sanctum')
            ->get("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")
            ->assertStatus(202)->json('data.export_id');

        $this->actingAs($teacher, 'sanctum')->getJson("/api/v1/teacher/exports/{$exportId}")
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.rows', 501);
        $file = $this->actingAs($teacher, 'sanctum')->get("/api/v1/teacher/exports/{$exportId}/download")->assertOk();
        $this->assertStringStartsWith('attempt_id,', ltrim($file->streamedContent(), "\xEF\xBB\xBF"));
    }

    public function test_a_small_export_still_streams_the_csv_and_stays_scoped(): void
    {
        [$teacher, $exam] = $this->examWithAttempts(3);

        $res = $this->actingAs($teacher, 'sanctum')
            ->get("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")
            ->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('attempt_id,', $res->streamedContent());

        $student = $this->createUserWithRole(UserRole::Student);
        $this->actingAs($student, 'sanctum')->get("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")->assertStatus(403);
        \Illuminate\Support\Facades\Config::set('app.co_teaching', false);
        $this->actingAs($this->createUserWithRole(UserRole::Teacher), 'sanctum')
            ->get("/api/v1/teacher/exams/{$exam->id}/results/export?format=csv")->assertStatus(403);
    }
}
