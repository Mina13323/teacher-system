<?php

namespace App\Http\Controllers\Student;

use App\Actions\Exam\StartExamAttemptAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartExamAttemptRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Http\Resources\StudentExamDetailResource;
use App\Http\Resources\StudentExamResource;
use App\Models\Exam;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(
        private readonly StartExamAttemptAction $startAttempt,
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $courseIds = $this->enrollments->enrolledCourseIds($request->user());

        $exams = Exam::query()
            ->published()
            ->whereIn('course_id', $courseIds)
            ->with('course')
            ->withCount(['questions', 'attempts'])
            ->latest()
            ->paginate($this->perPage($request, 15));

        return $this->success(StudentExamResource::collection($exams), 'Exams retrieved.');
    }

    public function show(Request $request, Exam $exam): JsonResponse
    {
        $this->assertAccessible($request, $exam);

        $exam->load('course')->loadCount(['questions', 'attempts']);

        $exam->setRelation('attempts', $exam->attempts()
            ->where('student_id', $request->user()->getKey())
            ->get());

        return $this->success(new StudentExamDetailResource($exam), 'Exam retrieved.');
    }

    public function attempts(Request $request, Exam $exam): JsonResponse
    {
        $this->assertAccessible($request, $exam);

        $attempts = $exam->attempts()
            ->where('student_id', $request->user()->getKey())
            ->latest('attempt_number')
            ->get();

        return $this->success($attempts->map(function ($attempt) {
            // Scores stay hidden until staff explicitly publish them, matching
            // the gate ExamResultResource applies. This endpoint previously
            // hand-built its payload and leaked score/percentage regardless of
            // grades_published_at.
            $published = $attempt->grades_published_at !== null;

            return [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status?->value,
                'grades_published' => $published,
                'score' => $published ? $attempt->score : null,
                'percentage' => $published ? $attempt->percentage : null,
                'started_at' => $attempt->started_at?->toISOString(),
                'submitted_at' => $attempt->submitted_at?->toISOString(),
            ];
        }), 'Attempts retrieved.');
    }

    public function start(StartExamAttemptRequest $request, Exam $exam): JsonResponse
    {
        $attempt = $this->startAttempt->execute($request->user(), $exam);

        $attempt->load(['exam', 'answers', 'attemptQuestions.attemptOptions']);

        return $this->success(
            new ExamAttemptResource($attempt),
            'Attempt started.',
            201
        );
    }

    private function assertAccessible(Request $request, Exam $exam): void
    {
        if (! $exam->status->isPublished()) {
            abort(404, 'Exam not found.');
        }

        if ($request->user()->isStudent()) {
            abort_unless(
                $request->user()->canTakeExams() && $request->user()->hasActiveAccess(),
                403,
                'You do not have access to exams.'
            );
        }

        abort_unless(
            $this->enrollments->isEnrolled($request->user(), $exam->course_id),
            403,
            'You do not have access to this exam.'
        );
    }
}
