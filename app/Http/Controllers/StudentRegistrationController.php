<?php

namespace App\Http\Controllers;

use App\Actions\Auth\CreateStudentAction;
use App\Http\Requests\PublicStudentRegistrationRequest;
use App\Models\StudentRegistrationLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentRegistrationController extends Controller
{
    public function __construct(private readonly CreateStudentAction $createStudent)
    {
    }

    public function show(string $token): JsonResponse
    {
        $link = $this->link($token);

        return $this->success([
            'teacher_name' => $link->teacher->name,
            'academic_years' => ['secondary_1', 'secondary_2', 'secondary_3'],
        ], 'Registration link is active.');
    }

    public function store(PublicStudentRegistrationRequest $request, string $token): JsonResponse
    {
        $link = $this->link($token);
        $student = $this->createStudent->execute($link->teacher, [
            ...$request->validated(),
            // Third-secondary students default to both subjects; the creation
            // action also normalizes earlier years to general automatically.
            'academic_subject' => 'both',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration complete.',
            'data' => [
                'student_code' => $student->generated_credentials['student_code'],
                'login' => $student->generated_credentials['login'],
                'temporary_password' => $student->generated_credentials['temporary_password'],
            ],
        ], 201);
    }

    /** @return StudentRegistrationLink */
    private function link(string $token): StudentRegistrationLink
    {
        $link = StudentRegistrationLink::query()
            ->with('teacher')
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->firstOrFail();

        return $link;
    }
}
