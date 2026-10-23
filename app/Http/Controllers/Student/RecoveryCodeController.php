<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\Auth\RecoveryCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The student's password recovery code: shown once on first use (store) and
 * confirmed as saved (confirm). See RecoveryCodeService.
 */
class RecoveryCodeController extends Controller
{
    public function __construct(private readonly RecoveryCodeService $recovery)
    {
    }

    /** A new code, returned once and never again. */
    public function store(Request $request): JsonResponse
    {
        return $this->success(
            ['recovery_code' => $this->recovery->issue($request->user())],
            'Recovery code created. Save it before continuing.'
        );
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->recovery->confirm($request->user());

        return $this->success(null, 'Recovery code saved.');
    }
}
