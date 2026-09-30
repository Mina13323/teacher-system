<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\ArchiveExamAction;
use App\Actions\Exam\CreateExamAction;
use App\Actions\Exam\DeleteExamAction;
use App\Actions\Exam\PublishExamAction;
use App\Actions\Exam\UpdateExamAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateExamRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Http\Resources\ExamDetailResource;
use App\Http\Resources\ExamResource;
use App\Models\Course;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    public function __construct(
        private readonly CreateExamAction $createExam,
        private readonly UpdateExamAction $updateExam,
        private readonly DeleteExamAction $deleteExam,
        private readonly PublishExamAction $publishExam,
        private readonly ArchiveExamAction $archiveExam,
    ) {
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        $this->authorize('viewAny', Exam::class);
        $this->authorize('view', $course);

        $exams = $course->exams()
            ->with(['course', 'creator', 'lesson'])
            ->withCount(['questions', 'attempts'])
            ->latest()
            ->paginate($this->perPage($request, 15));

        return $this->success(ExamResource::collection($exams), 'Exams retrieved.');
    }

    public function store(CreateExamRequest $request, Course $course): JsonResponse
    {
        $exam = $this->createExam->execute($course, $request->user(), $request->validated());

        return $this->success(
            new ExamResource($exam->load(['course', 'creator', 'lesson'])->loadCount(['questions', 'attempts'])),
            'Exam created.',
            201
        );
    }

    public function show(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        $exam->load(['course', 'creator', 'lesson', 'questions.options'])
            ->loadCount(['questions', 'attempts']);

        return $this->success(new ExamDetailResource($exam), 'Exam retrieved.');
    }

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        $exam = $this->updateExam->execute($exam, $request->validated());

        return $this->success(
            new ExamResource($exam->load(['course', 'creator', 'lesson'])->loadCount(['questions', 'attempts'])),
            'Exam updated.'
        );
    }

    public function publish(Exam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $exam = $this->publishExam->execute($exam);

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam published.'
        );
    }

    public function archive(Exam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        $exam = $this->archiveExam->execute($exam);

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam archived.'
        );
    }

    /**
     * Restores a soft-deleted exam. Attempt history is untouched either way.
     */
    public function restore(Exam $exam): JsonResponse
    {
        $this->authorize('update', $exam);

        if ($exam->trashed()) {
            $exam->restore();
        }

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('exam.restore', $exam, [
            'title' => $exam->title,
        ]);

        return $this->success(
            new ExamResource($exam->load(['course', 'creator'])->loadCount(['questions', 'attempts'])),
            'Exam restored.'
        );
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->authorize('delete', $exam);

        $this->deleteExam->execute($exam);

        return $this->success(null, 'Exam deleted.');
    }

    /**
     * Attempts grouped BY STUDENT (P1 attempt-management UX).
     *
     * One row per student with expandable attempts plus the derived summary a
     * teacher needs at a glance: best/latest result, outcome of the latest,
     * how many submissions still need grading, and integrity state. Optional
     * `search` filters students server-side (name/code/email/phone).
     */
    public function attemptsGrouped(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('viewAttempts', $exam);

        $query = $exam->attempts()
            ->with(['student', 'exam'])
            ->orderByDesc('started_at')
            // Deterministic "latest" on started_at ties (fast test runs, bursts).
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $grouped = $query->get()
            ->groupBy('student_id')
            ->map(function ($attempts, $studentId) {
                $best = $attempts
                    ->filter(fn ($a) => $a->score !== null && in_array($a->status->value, \App\Enums\ExamAttemptStatus::submittedValues(), true))
                    ->sortByDesc(fn ($a) => (float) $a->score)
                    ->first();
                $latest = $attempts->first();

                return [
                    'student_id' => $studentId,
                    'student' => $latest->student ? [
                        'id' => $latest->student->id,
                        'name' => $latest->student->name,
                        'student_code' => $latest->student->student_code,
                        'email' => $latest->student->email,
                    ] : null,
                    'attempts_count' => $attempts->count(),
                    'best' => $best ? [
                        'id' => $best->id,
                        'score' => $best->score,
                        'percentage' => $best->percentage,
                        'outcome' => $best->outcome()->value,
                    ] : null,
                    'latest' => [
                        'id' => $latest->id,
                        'status' => $latest->status->value,
                        'outcome' => $latest->outcome()->value,
                        'percentage' => $latest->percentage,
                        'submitted_at' => $latest->submitted_at?->toISOString(),
                        'end_reason' => $latest->end_reason,
                    ],
                    'pending_grading_count' => $attempts->filter(
                        fn ($a) => $a->status->value === \App\Enums\ExamAttemptStatus::Grading->value
                    )->count(),
                    'integrity' => [
                        'flagged_count' => $attempts->filter(
                            fn ($a) => $a->integrity_status === \App\Enums\IntegrityStatus::Flagged
                        )->count(),
                        'violation_warnings_total' => (int) $attempts->sum('violation_warnings'),
                    ],
                    'attempts' => \App\Http\Resources\ExamAttemptResource::collection(
                        $attempts->values()
                    )->resolve(),
                ];
            })
            ->values();

        return $this->success([
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'pass_percentage' => $exam->pass_percentage,
            ],
            'students' => $grouped,
            'summary' => [
                'students_count' => $grouped->count(),
                'attempts_count' => (int) $grouped->sum('attempts_count'),
                'pending_grading_count' => (int) $grouped->sum('pending_grading_count'),
                'flagged_count' => (int) $grouped->sum(fn ($g) => $g['integrity']['flagged_count']),
            ],
        ], 'Attempts grouped by student.');
    }

    public function attempts(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('viewAttempts', $exam);

        $attempts = $exam->attempts()
            ->with(['student', 'exam'])
            ->with('answers.selectedOptions')
            ->latest('started_at')
            ->paginate($this->perPage($request, 15));

        return $this->success(
            ExamAttemptDetailResource::collection($attempts),
            'Attempts retrieved.'
        );
    }
}
