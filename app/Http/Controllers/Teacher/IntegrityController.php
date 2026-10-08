<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Integrity\AssignExamIntegritySettingsAction;
use App\Actions\Integrity\ReviewExamAttemptIntegrityAction;
use App\Enums\IntegrityReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewExamAttemptIntegrityRequest;
use App\Http\Requests\UpdateExamIntegritySettingsRequest;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Http\Resources\ExamAttemptIntegrityResource;
use App\Http\Resources\ExamIntegritySettingsResource;
use App\Http\Resources\IntegrityEventResource;
use App\Http\Resources\IntegrityReviewResource;
use App\Enums\IntegrityStatus;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\Integrity\IntegrityRiskConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrityController extends Controller
{
    public function __construct(
        private readonly AssignExamIntegritySettingsAction $assignSettings,
        private readonly ReviewExamAttemptIntegrityAction $reviewAttempt,
        private readonly IntegrityRiskConfig $riskConfig,
    ) {
    }

    /**
     * Attempts with an integrity status worth a look (flagged, monitoring,
     * reviewed, cleared) across the staff member's courses, newest first.
     *
     * Replaces the review page walking every course, then every exam, then
     * each exam's attempts (up to about 150 requests in a row). The courses
     * are the ones the course list shows this user, and every exam still
     * passes the same `viewAttempts` policy check as the per-exam attempts
     * endpoint, so nothing beyond what that endpoint returns is exposed.
     */
    public function attentionAttempts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Course::class);

        $user = $request->user();
        $ownerIds = $user->staffOwnerIds();

        $courses = Course::query()
            ->when($ownerIds !== null, fn ($query) => $query->whereIn('created_by', $ownerIds))
            ->get()
            ->keyBy('id');

        $examIds = Exam::query()
            ->whereIn('course_id', $courses->keys())
            ->get()
            ->each(fn (Exam $exam) => $exam->setRelation('course', $courses->get($exam->course_id)))
            ->filter(fn (Exam $exam) => $user->can('viewAttempts', $exam))
            ->modelKeys();

        $attempts = ExamAttempt::query()
            ->whereIn('exam_id', $examIds)
            ->whereIn('integrity_status', [
                IntegrityStatus::Flagged->value,
                IntegrityStatus::Monitoring->value,
                IntegrityStatus::Reviewed->value,
                IntegrityStatus::Cleared->value,
            ])
            ->with(['student.roles', 'student.latestAccessPeriod', 'exam'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        // The page's headline count (it used to need the whole analytics
        // overview request): flagged or monitored attempts in the same scope.
        $needsAttention = ExamAttempt::query()
            ->whereIn('exam_id', $examIds)
            ->whereIn('integrity_status', [IntegrityStatus::Flagged->value, IntegrityStatus::Monitoring->value])
            ->count();

        return $this->success([
            'needs_attention_count' => $needsAttention,
            'attempts' => $attempts->map(fn (ExamAttempt $attempt) => array_merge(
                (new ExamAttemptDetailResource($attempt))->resolve($request),
                ['course_title' => $courses->get($attempt->exam?->course_id)?->title]
            ))->values(),
        ], 'Attempts needing attention retrieved.');
    }

    public function showSettings(Exam $exam): JsonResponse
    {
        $this->authorize('view', $exam);

        $settings = $exam->integritySetting()->first();

        if (! $settings) {
            // No explicit configuration yet; surface the effective defaults.
            return $this->success([
                'exam_id' => $exam->id,
                'configured' => false,
                'settings' => $this->riskConfig->defaults(),
            ], 'Integrity settings retrieved.');
        }

        return $this->success([
            'exam_id' => $exam->id,
            'configured' => true,
            'settings' => new ExamIntegritySettingsResource($settings),
        ], 'Integrity settings retrieved.');
    }

    public function updateSettings(UpdateExamIntegritySettingsRequest $request, Exam $exam): JsonResponse
    {
        $settings = $this->assignSettings->execute($exam, $request->validated());

        return $this->success(
            new ExamIntegritySettingsResource($settings),
            'Integrity settings updated.'
        );
    }

    public function showAttemptIntegrity(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('viewIntegrity', $attempt);

        $attempt->load([
            'exam',
            'student',
            'integrityEvents' => fn ($q) => $q->orderByDesc('occurred_at'),
            'integritySetting',
            'integrityReviews' => fn ($q) => $q->with('reviewer')->latest('reviewed_at'),
        ]);

        return $this->success(new ExamAttemptIntegrityResource($attempt), 'Attempt integrity retrieved.');
    }

    public function indexAttemptEvents(Request $request, ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('viewIntegrity', $attempt);

        // A heavily proctored attempt can log hundreds of events, so the log is
        // paged rather than returned in one unbounded payload.
        $events = $attempt->integrityEvents()
            ->latest('occurred_at')
            ->paginate($this->perPage($request));

        return $this->success(IntegrityEventResource::collection($events), 'Integrity events retrieved.');
    }

    public function review(ReviewExamAttemptIntegrityRequest $request, ExamAttempt $attempt): JsonResponse
    {
        $decision = IntegrityReviewDecision::from($request->validated('decision'));

        try {
            $review = $this->reviewAttempt->execute(
                $request->user(),
                $attempt,
                $decision,
                $request->validated('note')
            );
        } catch (\DomainException $e) {
            // e.g. RESUME on an attempt that is not integrity-terminated.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        return $this->success(
            new IntegrityReviewResource($review),
            'Integrity review recorded.',
            201
        );
    }
}
