<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\JsonResponse;

/**
 * PUBLIC certificate verification (P2).
 *
 * Resolves a verification code to the MINIMUM disclosure only: student
 * display name, course title, issue date. Unknown codes are a clean 404
 * (no enumeration hints). Rate limited at the route level.
 */
class CertificateVerificationController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $certificate = Certificate::query()
            ->with(['student', 'course'])
            ->where('code', $code)
            ->first();

        abort_if(! $certificate, 404, 'Certificate not found.');

        return $this->success([
            'valid' => true,
            'code' => $certificate->code,
            'student_name' => $certificate->student?->name,
            'course_title' => $certificate->course?->title,
            'issued_at' => $certificate->issued_at?->toISOString(),
        ], 'Certificate verified.');
    }
}
