<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExamAttemptDetailResource;
use App\Models\ExamAttempt;
use Illuminate\Http\JsonResponse;

class AttemptController extends Controller
{
    public function show(ExamAttempt $attempt): JsonResponse
    {
        $this->authorize('view', $attempt);

        $attempt->load(['exam', 'student', 'answers']);

        return $this->success(new ExamAttemptDetailResource($attempt), 'Attempt retrieved.');
    }
}
