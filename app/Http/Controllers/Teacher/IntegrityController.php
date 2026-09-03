<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Integrity\AssignExamIntegritySettingsAction;
use App\Actions\Integrity\ReviewExamAttemptIntegrityAction;
use App\Enums\IntegrityReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewExamAttemptIntegrityRequest;
use App\Http\Requests\UpdateExamIntegritySettingsRequest;
use App\Http\Resources\ExamAttemptIntegrityResource;
use App\Http\Resources\ExamIntegritySettingsResource;
use App\Http\Resources\IntegrityEventResource;
use App\Http\Resources\IntegrityReviewResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\Integrity\IntegrityRiskConfig;
use Illuminate\Http\JsonResponse;

class IntegrityController extends Controller
{
    public function __construct(
        private readonly AssignExamIntegritySettingsAction $assignSettings,
        private readonly ReviewExamAttemptIntegrityAction $reviewAttempt,
        private readonly IntegrityRiskConfig $riskConfig,
    ) {
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

    public function indexAttemptEvents(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('viewIntegrity', $attempt);

        $events = $attempt->integrityEvents()->latest('occurred_at')->get();

        return $this->success(IntegrityEventResource::collection($events), 'Integrity events retrieved.');
    }

    public function review(ReviewExamAttemptIntegrityRequest $request, ExamAttempt $attempt): JsonResponse
    {
        $decision = IntegrityReviewDecision::from($request->validated('decision'));

        $review = $this->reviewAttempt->execute(
            $request->user(),
            $attempt,
            $decision,
            $request->validated('note')
        );

        return $this->success(
            new IntegrityReviewResource($review),
            'Integrity review recorded.',
            201
        );
    }
}
