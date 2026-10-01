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
        $this->assertStudentCanUseExams($request);

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

        $exam->load(['course', 'integritySetting'])->loadCount(['questions', 'attempts']);

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
        $this->assertStudentCanUseExams($request);

        $attempt = $this->startAttempt->execute(
            $request->user(),
            $exam,
            $request->boolean('rules_acknowledged')
        );

        // §14 multiple sessions: a second tab/device must land on the SAME
        // attempt (unique active_key enforces it at the DB level). Tell the
        // client explicitly so it can explain — never silently duplicate.
        $alreadyOpen = ! $attempt->wasRecentlyCreated;

        if ($request->boolean('compact_response')) {
            // The app navigates to /attempts/{id} immediately, where it fetches
            // the full question snapshot. Avoid serializing and transferring
            // that large payload twice; retain the full response by default
            // for older API clients.
            $payload = [
                'id' => $attempt->getKey(),
                'exam_id' => $attempt->exam_id,
                'attempt_number' => $attempt->attempt_number,
                'status' => $attempt->status?->value,
                'started_at' => $attempt->started_at?->toISOString(),
                'expires_at' => $attempt->expires_at?->toISOString(),
                'already_open' => $alreadyOpen,
            ];
        } else {
            $attempt->load(['exam', 'answers.selectedOptions', 'attemptQuestions.attemptOptions', 'integritySetting']);
            $payload = (new ExamAttemptResource($attempt))->resolve();
            $payload['already_open'] = $alreadyOpen;
        }

        return $this->success(
            $payload,
            $alreadyOpen
                ? 'This exam is already open in another session — your saved attempt was restored.'
                : 'Attempt started.',
            201
        );
    }

    private function assertStudentCanUseExams(Request $request): void
    {
        abort_unless($request->user()->isStudent(), 403, 'Student account required.');
        abort_unless(
            $request->user()->canTakeExams() && $request->user()->hasActiveAccess(),
            403,
            'You do not have access to exams.'
        );
    }

    private function assertAccessible(Request $request, Exam $exam): void
    {
        if (! $exam->status->isPublished()) {
            abort(404, 'Exam not found.');
        }

        $this->assertStudentCanUseExams($request);

        abort_unless(
            $this->enrollments->isEnrolled($request->user(), $exam->course_id),
            403,
            'You do not have access to this exam.'
        );
    }
}
