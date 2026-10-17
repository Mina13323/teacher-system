<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A student's exam attempts for the staff student page, newest exam first and
 * each exam's attempts in order, so the screen can show "student -> exam ->
 * attempts" without loading every attempt at once (server-paginated).
 *
 * Scoped like the teacher dashboard: only exams in courses owned by the
 * viewer's staff scope (every exam for admins). Unpublished scores are shown
 * to staff as they are elsewhere on staff screens.
 */
class StudentAttemptController extends Controller
{
    public function index(Request $request, User $student): JsonResponse
    {
        $this->authorize('view', $student);

        $filters = $request->validate([
            'exam_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $ownerIds = $request->user()->staffOwnerIds();

        $query = ExamAttempt::query()
            ->where('student_id', $student->getKey())
            ->whereHas('exam.course', function ($course) use ($ownerIds) {
                if ($ownerIds !== null) {
                    $course->whereIn('created_by', $ownerIds);
                }
            })
            ->with(['exam:id,title,course_id', 'integrityReviews'])
            ->orderByDesc('exam_id')
            ->orderBy('attempt_number')
            ->orderBy('id');

        if (! empty($filters['exam_id'])) {
            $query->where('exam_id', (int) $filters['exam_id']);
        }

        $page = $query->paginate($this->perPage($request))->withQueryString();

        $rows = collect($page->items())->map(function (ExamAttempt $a) {
            $outcome = $a->outcome();

            return [
                'attempt_id' => $a->id,
                'exam_id' => $a->exam_id,
                'exam_title' => $a->exam?->title,
                'attempt_number' => $a->attempt_number,
                'status' => $a->displayStatus(),
                'end_reason' => $a->end_reason,
                'started_at' => $a->started_at?->toISOString(),
                'submitted_at' => $a->submitted_at?->toISOString(),
                'grades_published' => $a->grades_published_at !== null,
                // NULL when there is no score yet (never shown as 0).
                'score' => $a->score,
                'percentage' => $a->percentage,
                'outcome' => $outcome->value,
                'integrity_status' => $a->integrity_status?->value,
            ];
        })->values();

        return $this->success([
            'attempts' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ], 'Student attempts retrieved.');
    }
}
