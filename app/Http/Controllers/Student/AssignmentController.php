<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Student assignment workflow (P2): browse published assignments in enrolled
 * courses, submit text and/or a file (stored privately), read own submission
 * and — after grading — the teacher's score + feedback.
 */
class AssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courseIds = app(\App\Services\EnrollmentService::class)->enrolledCourseIds($request->user());

        $assignments = Assignment::query()
            ->where('is_published', true)
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->input('course_id')))
            ->withCount(['submissions as my_submission_count' => fn ($q) => $q->where('student_id', $request->user()->getKey())])
            ->orderBy('due_at')
            ->orderBy('id')
            ->paginate($this->perPage($request, 15));

        return $this->success(AssignmentResource::collection($assignments), 'Assignments retrieved.');
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('viewAsStudent', $assignment);

        $submission = $assignment->submissions()
            ->where('student_id', $request->user()->getKey())
            ->first();

        return $this->success([
            'assignment' => (new AssignmentResource($assignment))->resolve($request),
            'my_submission' => $submission ? (new AssignmentSubmissionResource($submission))->resolve($request) : null,
        ], 'Assignment retrieved.');
    }

    public function submit(SubmitAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('submit', $assignment);

        $submission = $assignment->submissions()
            ->where('student_id', $request->user()->getKey())
            ->first();

        if ($submission?->isGraded()) {
            return $this->error('This submission has already been graded. Ask your teacher for a regrade.', 422);
        }

        $data = [
            'answer_text' => $request->input('answer_text'),
            'status' => AssignmentSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            // Replace any previous upload for this submission (single row).
            if ($submission?->file_path) {
                Storage::disk('local')->delete($submission->file_path);
            }

            $data['file_path'] = $file->store('assignments/'.$assignment->getKey(), 'local');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_mime'] = $file->getClientMimeType();
        } elseif ($submission?->file_path && $request->boolean('remove_file')) {
            // Explicit removal only — a text-only resubmission never silently
            // discards a previously uploaded file.
            Storage::disk('local')->delete($submission->file_path);
            $data['file_path'] = null;
            $data['file_name'] = null;
            $data['file_mime'] = null;
        }

        if (($data['answer_text'] ?? null) === null && ($data['file_path'] ?? $submission?->file_path) === null) {
            return $this->error('Provide a written answer or attach a file.', 422);
        }

        if ($submission) {
            $submission->update($data);
        } else {
            $submission = $assignment->submissions()->create([
                ...$data,
                'student_id' => $request->user()->getKey(),
            ]);
        }

        return $this->success(
            new AssignmentSubmissionResource($submission->fresh()),
            'Submission saved.'
        , 201);
    }

    /**
     * Authorized download of the student's own submission file (staff use the
     * same endpoint; the policy admits both).
     */
    public function downloadFile(Request $request, AssignmentSubmission $submission): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('viewFile', $submission);

        abort_unless($submission->file_path && Storage::disk('local')->exists($submission->file_path), 404, 'File not found.');

        return Storage::disk('local')->download(
            $submission->file_path,
            $submission->file_name ?: 'submission',
            ['Content-Type' => $submission->file_mime ?: 'application/octet-stream']
        );
    }
}
