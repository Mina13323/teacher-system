<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Exam\ArchiveExamAction;
use App\Actions\Exam\AssignExamMakeUpAction;
use App\Actions\Exam\CreateExamAction;
use App\Actions\Exam\DeleteExamAttemptsAction;
use App\Actions\Exam\RevokeExamMakeUpAction;
use App\Actions\Exam\DeleteExamAction;
use App\Actions\Exam\PublishExamAction;
use App\Actions\Exam\UpdateExamAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignExamMakeUpRequest;
use App\Http\Requests\CreateExamRequest;
use App\Http\Requests\DeleteExamAttemptsRequest;
use App\Http\Requests\UpdateExamRequest;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Http\Resources\ExamAttemptSummaryResource;
use App\Http\Resources\ExamMakeUpAssignmentResource;
use App\Http\Resources\ExamDetailResource;
use App\Http\Resources\ExamResource;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamMakeUpAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public function __construct(
        private readonly CreateExamAction $createExam,
        private readonly UpdateExamAction $updateExam,
        private readonly DeleteExamAction $deleteExam,
        private readonly PublishExamAction $publishExam,
        private readonly ArchiveExamAction $archiveExam,
        private readonly DeleteExamAttemptsAction $deleteAttempts,
        private readonly AssignExamMakeUpAction $assignMakeUps,
        private readonly RevokeExamMakeUpAction $revokeMakeUp,
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

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('exam.create', $exam, [
            'course_id' => (string) $course->getKey(),
            'title' => (string) $exam->title,
        ]);

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

        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('exam.update', $exam, [
            'title' => (string) $exam->title,
        ]);

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

        $this->finalizeExpiredAttempts($exam);
        $filters = $this->attemptFilterValues($request);

        // Page student groups in SQL; do not hydrate the whole exam history to
        // perform filtering/grouping in PHP. Each page then loads only attempts
        // belonging to those students and matching the same filters.
        $groupQuery = $exam->attempts();
        $this->applyAttemptFilters($groupQuery, $filters);
        $studentPage = (clone $groupQuery)
            ->select('student_id')
            ->selectRaw('MAX(started_at) as last_started_at')
            ->groupBy('student_id')
            ->orderByDesc('last_started_at')
            ->orderByDesc('student_id')
            ->paginate($this->perPage($request, 15))
            ->withQueryString();

        $studentIds = collect($studentPage->items())
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pageAttemptsQuery = $exam->attempts()
            ->with('student')
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
        $this->applyAttemptFilters($pageAttemptsQuery, $filters);
        $pageAttempts = $studentIds === [] ? collect() : $pageAttemptsQuery->get();

        $grouped = $pageAttempts
            ->groupBy('student_id')
            ->map(function ($attempts, $studentId) {
                $best = $attempts
                    ->filter(fn ($attempt) => $attempt->hasFinalScore() && $attempt->score !== null)
                    ->sortByDesc(fn ($attempt) => (float) $attempt->score)
                    ->first();
                $latest = $attempts->first();

                return [
                    'student_id' => (int) $studentId,
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
                        'status' => $latest->status?->value,
                        'outcome' => $latest->outcome()->value,
                        'score' => $latest->score,
                        'percentage' => $latest->percentage,
                        'submitted_at' => $latest->submitted_at?->toISOString(),
                        'end_reason' => $latest->end_reason,
                    ],
                    'pending_grading_count' => $attempts->filter(
                        fn ($attempt) => $attempt->status?->value === \App\Enums\ExamAttemptStatus::Grading->value
                    )->count(),
                    'integrity' => [
                        'flagged_count' => $attempts->filter(
                            fn ($attempt) => $attempt->integrity_status === \App\Enums\IntegrityStatus::Flagged
                        )->count(),
                        'violation_warnings_total' => (int) $attempts->sum('violation_warnings'),
                    ],
                    // This is a teacher-only summary resource: unlike the
                    // student resource it intentionally includes staff scores.
                    'attempts' => ExamAttemptSummaryResource::collection($attempts->values())->resolve(),
                ];
            })
            ->values();

        $summaryQuery = $exam->attempts();
        $this->applyAttemptFilters($summaryQuery, $filters);
        $summaryAttempts = (clone $summaryQuery)->count();
        $pendingGrading = (clone $summaryQuery)
            ->where('status', \App\Enums\ExamAttemptStatus::Grading->value)
            ->count();
        $flaggedStudents = (clone $summaryQuery)
            ->where('integrity_status', \App\Enums\IntegrityStatus::Flagged->value)
            ->distinct()
            ->count('student_id');

        return $this->success([
            'exam' => [
                'id' => $exam->id,
                'title' => $exam->title,
                'pass_percentage' => $exam->pass_percentage,
            ],
            'students' => $grouped,
            'pagination' => [
                'current_page' => $studentPage->currentPage(),
                'last_page' => $studentPage->lastPage(),
                'per_page' => $studentPage->perPage(),
                'total' => $studentPage->total(),
            ],
            'summary' => [
                'students_count' => $studentPage->total(),
                'attempts_count' => $summaryAttempts,
                'pending_grading_count' => $pendingGrading,
                'flagged_count' => $flaggedStudents,
            ],
        ], 'Attempts grouped by student.');
    }

    public function attempts(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('viewAttempts', $exam);

        $this->finalizeExpiredAttempts($exam);
        $filters = $this->attemptFilterValues($request);

        $query = $exam->attempts()
            ->with(['student', 'exam', 'answers.selectedOptions', 'attemptQuestions.attemptOptions'])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
        $this->applyAttemptFilters($query, $filters);

        $attempts = $query
            ->paginate($this->perPage($request, 15))
            ->withQueryString();

        return $this->success(
            ExamAttemptDetailResource::collection($attempts),
            'Attempts retrieved.'
        );
    }

    /** @return array<string, mixed> */
    private function attemptFilterValues(Request $request): array
    {
        $statuses = array_map(
            fn ($status) => $status->value,
            \App\Enums\ExamAttemptStatus::cases()
        );
        $integrityStatuses = array_map(
            fn ($status) => $status->value,
            \App\Enums\IntegrityStatus::cases()
        );

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'student_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in($statuses)],
            // `score=0` is a valid exact filter; do not use Request::filled().
            'score' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'score_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'score_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'percentage_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'percentage_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'integrity_status' => ['sometimes', 'string', Rule::in($integrityStatuses)],
            'grades_published' => ['sometimes', 'boolean'],
            'started_from' => ['sometimes', 'nullable', 'date'],
            'started_to' => ['sometimes', 'nullable', 'date'],
        ]);

        if (
            isset($filters['percentage_min'], $filters['percentage_max'])
            && (float) $filters['percentage_min'] > (float) $filters['percentage_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'percentage_max' => ['The maximum percentage must be greater than or equal to the minimum.'],
            ]);
        }

        if (
            isset($filters['score_min'], $filters['score_max'])
            && (int) $filters['score_min'] > (int) $filters['score_max']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'score_max' => ['The maximum score must be greater than or equal to the minimum.'],
            ]);
        }

        if (
            isset($filters['started_from'], $filters['started_to'])
            && strtotime($filters['started_from']) > strtotime($filters['started_to'])
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'started_to' => ['The end of the date range must not precede the start.'],
            ]);
        }

        return $filters;
    }

    /** @param  Builder<\App\Models\ExamAttempt>  $query */
    private function applyAttemptFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['student_id'])) {
            $query->where('student_id', (int) $filters['student_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('score', $filters) && $filters['score'] !== null) {
            $query->where('score', (int) $filters['score']);
        }
        if (array_key_exists('score_min', $filters) && $filters['score_min'] !== null) {
            $query->where('score', '>=', (int) $filters['score_min']);
        }
        if (array_key_exists('score_max', $filters) && $filters['score_max'] !== null) {
            $query->where('score', '<=', (int) $filters['score_max']);
        }
        if (array_key_exists('percentage_min', $filters) && $filters['percentage_min'] !== null) {
            $query->where('percentage', '>=', (float) $filters['percentage_min']);
        }
        if (array_key_exists('percentage_max', $filters) && $filters['percentage_max'] !== null) {
            $query->where('percentage', '<=', (float) $filters['percentage_max']);
        }

        if (isset($filters['integrity_status'])) {
            $query->where('integrity_status', $filters['integrity_status']);
        }

        if (array_key_exists('grades_published', $filters)) {
            filter_var($filters['grades_published'], FILTER_VALIDATE_BOOLEAN)
                ? $query->whereNotNull('grades_published_at')
                : $query->whereNull('grades_published_at');
        }

        if (! empty($filters['started_from'])) {
            $query->where('started_at', '>=', $filters['started_from']);
        }
        if (! empty($filters['started_to'])) {
            $query->where('started_at', '<=', $filters['started_to']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->whereHas('student', function ($studentQuery) use ($search) {
                $studentQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    public function deleteSelectedAttempts(DeleteExamAttemptsRequest $request, Exam $exam): JsonResponse
    {
        $attemptIds = $this->deleteAttempts->execute(
            $request->user(),
            $exam,
            $request->validated('attempt_ids'),
            $request->validated('reason')
        );

        return $this->success([
            'deleted_ids' => $attemptIds,
            'deleted_count' => count($attemptIds),
        ], 'Selected attempts were soft-deleted and audited.');
    }

    public function makeUpAssignments(Request $request, Exam $exam): JsonResponse
    {
        $this->authorize('assignMakeUp', $exam);

        $assignments = $exam->makeUpAssignments()
            ->with('student')
            ->latest('assigned_at')
            ->paginate($this->perPage($request, 20));

        return $this->success(
            ExamMakeUpAssignmentResource::collection($assignments),
            'Make-up assignments retrieved.'
        );
    }

    public function assignMakeUps(AssignExamMakeUpRequest $request, Exam $exam): JsonResponse
    {
        $assignments = $this->assignMakeUps->execute(
            $request->user(),
            $exam,
            $request->validated('student_ids'),
            $request->validated('reason')
        );

        $assignments = collect($assignments)->map(fn (ExamMakeUpAssignment $assignment) => $assignment->load('student'));

        return $this->success(
            ExamMakeUpAssignmentResource::collection($assignments),
            'Individual make-up attempts assigned.',
            201
        );
    }

    public function revokeMakeUp(Request $request, Exam $exam, ExamMakeUpAssignment $assignment): JsonResponse
    {
        $this->authorize('assignMakeUp', $exam);

        $assignment = $this->revokeMakeUp->execute($request->user(), $exam, $assignment);

        return $this->success(new ExamMakeUpAssignmentResource($assignment), 'Make-up assignment revoked.');
    }

    /**
     * Ensure any in-progress attempts whose server deadline has already passed
     * are finalized and auto-graded immediately when staff open the attempts
     * list, even if the background scheduler has not run yet.
     */
    private function finalizeExpiredAttempts(Exam $exam): void
    {
        $query = $exam->attempts()
            ->where('status', \App\Enums\ExamAttemptStatus::InProgress->value);

        // A closed exam window makes every still-open attempt eligible. Before
        // that, only inspect attempts that have a missing or elapsed deadline.
        if ($exam->ends_at === null || ! $exam->ends_at->isPast()) {
            $query->where(function ($candidate) {
                $candidate->whereNull('expires_at')
                    ->orWhere('expires_at', '<=', now());
            });
        }

        $query->orderBy('id')->chunkById(100, function ($attempts) use ($exam) {
            $finalizer = app(\App\Actions\Exam\FinalizeExpiredAttemptAction::class);

            foreach ($attempts as $attempt) {
                $attempt->setRelation('exam', $exam);

                if ($attempt->expires_at === null && $attempt->started_at !== null) {
                    $computedExpiry = $exam->calculateAttemptExpiry($attempt->started_at);
                    if ($computedExpiry && $computedExpiry->isPast()) {
                        $attempt->expires_at = $computedExpiry;
                        $attempt->save();
                    }
                }

                if ($attempt->isExpired()) {
                    $finalizer->execute($attempt);
                }
            }
        });
    }

}
