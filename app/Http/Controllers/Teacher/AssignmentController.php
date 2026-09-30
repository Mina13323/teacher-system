<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\GradeAssignmentSubmissionRequest;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher/assistant assignment management (P2):
 * create/update/publish/soft-delete per course, review + grade submissions.
 */
class AssignmentController extends Controller
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        $assignments = $course->assignments()
            ->withCount([
                'submissions',
                'submissions as submitted_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_SUBMITTED),
                'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
            ])
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 15));

        return $this->success(AssignmentResource::collection($assignments), 'Assignments retrieved.');
    }

    public function store(StoreAssignmentRequest $request, Course $course): JsonResponse
    {
        $this->authorize('update', $course);

        $assignment = $course->assignments()->create([
            ...$request->safe()->only(['title', 'description', 'points', 'due_at', 'unit_id', 'lesson_id']),
            'points' => $request->input('points', 100),
            'is_published' => (bool) $request->input('is_published', false),
            'created_by' => $request->user()->getKey(),
        ]);

        $this->auditLog->execute('assignment.create', $assignment, [
            'course_id' => $course->id,
            'title' => $assignment->title,
        ]);

        return $this->success(new AssignmentResource($assignment), 'Assignment created.', 201);
    }

    public function show(Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $assignment->loadCount([
            'submissions',
            'submissions as submitted_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_SUBMITTED),
            'submissions as graded_count' => fn ($q) => $q->where('status', AssignmentSubmission::STATUS_GRADED),
        ]);

        return $this->success(new AssignmentResource($assignment), 'Assignment retrieved.');
    }

    public function update(StoreAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $assignment->update($request->safe()->only(['title', 'description', 'points', 'due_at', 'unit_id', 'lesson_id', 'is_published']));

        return $this->success(new AssignmentResource($assignment->fresh()), 'Assignment updated.');
    }

    public function publish(Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $assignment->update(['is_published' => true]);
        $this->auditLog->execute('assignment.publish', $assignment, ['title' => $assignment->title]);

        return $this->success(new AssignmentResource($assignment->fresh()), 'Assignment published.');
    }

    public function unpublish(Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $assignment->update(['is_published' => false]);

        return $this->success(new AssignmentResource($assignment->fresh()), 'Assignment unpublished.');
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);

        // Submissions (student work, grades) are NEVER deleted — the assignment
        // is only hidden. The rows keep pointing at the soft-deleted parent.
        $assignment->delete();

        $this->auditLog->execute('assignment.delete', $assignment, [
            'title' => $assignment->title,
            'mode' => 'soft',
        ]);

        return $this->success(null, 'Assignment deleted.');
    }

    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('update', $assignment);

        $submissions = $assignment->submissions()
            ->with(['student'])
            ->orderBy('status')
            ->orderBy('student_id')
            ->paginate($this->perPage($request, 20));

        return $this->success(AssignmentSubmissionResource::collection($submissions), 'Submissions retrieved.');
    }

    public function grade(GradeAssignmentSubmissionRequest $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorize('gradeSubmission', $submission);

        $assignment = $submission->assignment;

        if ((int) $request->input('score') > (int) $assignment->points) {
            return $this->error("Score cannot exceed the assignment's {$assignment->points} points.", 422);
        }

        $submission->update([
            'score' => (int) $request->input('score'),
            'feedback' => $request->input('feedback'),
            'graded_by' => $request->user()->getKey(),
            'graded_at' => now(),
            'status' => AssignmentSubmission::STATUS_GRADED,
        ]);

        $this->auditLog->execute('assignment.grade', $submission, [
            'assignment_id' => $assignment->id,
            'student_id' => $submission->student_id,
            'score' => $submission->score,
            'max_points' => $assignment->points,
        ]);

        return $this->success(new AssignmentSubmissionResource($submission->fresh(['student'])), 'Submission graded.');
    }
}
