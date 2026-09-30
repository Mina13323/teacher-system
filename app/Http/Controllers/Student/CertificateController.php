<?php

namespace App\Http\Controllers\Student;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Certificate\IssueCertificateAction;
use App\Exceptions\InvalidAttemptStateException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Course;
use App\Models\Certificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student certificates (P2): list my certificates, issue (idempotent) when the
 * course is complete, fetch the one for a course.
 */
class CertificateController extends Controller
{
    public function __construct(
        private readonly IssueCertificateAction $issueCertificate,
        private readonly RecordAuditLogAction $auditLog,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $certificates = Certificate::query()
            ->where('student_id', $request->user()->getKey())
            ->with(['course', 'student'])
            ->orderByDesc('issued_at')
            ->paginate($this->perPage($request, 15));

        return $this->success(CertificateResource::collection($certificates), 'Certificates retrieved.');
    }

    /**
     * Issue-or-return the course certificate. Idempotent: repeated calls
     * return the SAME certificate (same verification code).
     */
    public function issue(Request $request, Course $course): JsonResponse
    {
        abort_unless(
            app(\App\Services\EnrollmentService::class)->isEnrolled($request->user(), $course->getKey()),
            403,
            'You are not enrolled in this course.'
        );

        try {
            $certificate = $this->issueCertificate->execute($request->user(), $course);
        } catch (InvalidAttemptStateException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Only log the FIRST issuance (a fresh row, not the idempotent return).
        if ($certificate->wasRecentlyCreated) {
            $this->auditLog->execute('certificate.issue', $certificate, [
                'course_id' => $course->id,
                'student_id' => $request->user()->getKey(),
            ]);
        }

        return $this->success(
            new CertificateResource($certificate->load(['course', 'student'])),
            'Certificate ready.',
            $certificate->wasRecentlyCreated ? 201 : 200
        );
    }

    public function showForCourse(Request $request, Course $course): JsonResponse
    {
        $certificate = Certificate::query()
            ->where('student_id', $request->user()->getKey())
            ->where('course_id', $course->getKey())
            ->first();

        abort_if(! $certificate, 404, 'No certificate earned for this course yet.');

        return $this->success(new CertificateResource($certificate->load(['course', 'student'])), 'Certificate retrieved.');
    }
}
