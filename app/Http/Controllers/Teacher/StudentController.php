<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Auth\CreateStudentAction;
use App\Actions\Auth\RegenerateStudentCredentialsAction;
use App\Actions\Auth\ResetUserPasswordAction;
use App\Actions\Auth\SetAccountActiveStateAction;
use App\Actions\Auth\UpdateAccountEmailAction;
use App\Actions\Auth\UpdateUserAccountAction;
use App\Actions\Enrollment\EnrollStudentToCourseAction;
use App\Actions\Student\AllowStudentImmediatelyAction;
use App\Actions\Student\RenewStudentAccessAction;
use App\Actions\Student\RestoreStudentAction;
use App\Actions\Student\SuspendStudentAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStudentRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Student account management for a teacher-owned LMS. A teacher or authorized assistant
 * may manage students they created or students enrolled in courses they own.
 */
class StudentController extends Controller
{
    public function __construct(
        private readonly CreateStudentAction $createStudent,
        private readonly UpdateUserAccountAction $updateAccount,
        private readonly UpdateAccountEmailAction $updateEmail,
        private readonly SetAccountActiveStateAction $setActiveState,
        private readonly ResetUserPasswordAction $resetPassword,
        private readonly RegenerateStudentCredentialsAction $regenerateCredentials,
        private readonly RenewStudentAccessAction $renewAccess,
        private readonly SuspendStudentAction $suspendStudent,
        private readonly RestoreStudentAction $restoreStudent,
        private readonly AllowStudentImmediatelyAction $allowStudentImmediately,
        private readonly EnrollStudentToCourseAction $enrollStudent,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $query = app(\App\Services\Student\ManagedStudentScope::class)->query($request->user())
            ->with(['roles', 'latestAccessPeriod']);

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->string('academic_year')->toString());
        }

        if ($request->filled('academic_subject')) {
            $query->where('academic_subject', $request->string('academic_subject')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->trim()->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('student_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'suspended' || $status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('capability')) {
            $cap = $request->string('capability')->toString();
            if ($cap === 'lessons') {
                $query->where('can_access_lessons', true);
            } elseif ($cap === 'exams') {
                $query->where('can_take_exams', true);
            } elseif ($cap === 'competitions') {
                $query->where('can_join_competitions', true);
            }
        }

        if ($request->boolean('duplicates')) {
            $scopedIds = (clone $query)->pluck('users.id');

            $duplicateNames = User::query()
                ->whereIn('id', $scopedIds)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->selectRaw('TRIM(name) as dup_name')
                ->groupBy('dup_name')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('dup_name');

            $duplicatePhones = User::query()
                ->whereIn('id', $scopedIds)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->selectRaw('TRIM(phone) as dup_phone')
                ->groupBy('dup_phone')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('dup_phone');

            $query->where(function ($q) use ($duplicateNames, $duplicatePhones) {
                $hasCond = false;
                if ($duplicateNames->isNotEmpty()) {
                    $q->whereIn(DB::raw('TRIM(name)'), $duplicateNames);
                    $hasCond = true;
                }
                if ($duplicatePhones->isNotEmpty()) {
                    if ($hasCond) {
                        $q->orWhereIn(DB::raw('TRIM(phone)'), $duplicatePhones);
                    } else {
                        $q->whereIn(DB::raw('TRIM(phone)'), $duplicatePhones);
                    }
                    $hasCond = true;
                }
                if (! $hasCond) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $sort = $request->string('sort', 'name_asc')->toString();
        switch ($sort) {
            case 'name_desc':
                $query->orderBy('name', 'desc')->orderBy('id', 'asc');
                break;
            case 'latest':
                $query->latest();
                break;
            case 'oldest':
                $query->oldest();
                break;
            case 'code_asc':
                $query->orderBy('student_code', 'asc');
                break;
            case 'code_desc':
                $query->orderBy('student_code', 'desc');
                break;
            case 'name_asc':
            default:
                $query->orderBy('name', 'asc')->orderBy('id', 'asc');
                break;
        }

        $students = $query->paginate($this->perPage($request));

        // TRIM() on the column cannot use an index, so the old query scanned the
        // whole users table twice per page. Match the page's raw and trimmed
        // values against the indexed column, then compare trimmed in PHP.
        $dupNames = $this->duplicateValues('name', $students->getCollection()->pluck('name'));
        $dupPhones = $this->duplicateValues('phone', $students->getCollection()->pluck('phone'));

        $dupNamesSet = array_flip($dupNames);
        $dupPhonesSet = array_flip($dupPhones);

        $students->getCollection()->each(function ($user) use ($dupNamesSet, $dupPhonesSet) {
            $user->is_duplicate = (isset($dupNamesSet[trim($user->name)]) || ($user->phone && isset($dupPhonesSet[trim($user->phone)])));
        });

        return $this->success(StudentResource::collection($students), 'Students retrieved.');
    }

    /**
     * Trimmed values among $values that more than one student shares.
     *
     * @param  \Illuminate\Support\Collection<int, string|null>  $values
     * @return list<string>
     */
    private function duplicateValues(string $column, \Illuminate\Support\Collection $values): array
    {
        $candidates = $values->filter()
            ->flatMap(fn ($v) => [$v, trim($v)])
            ->unique()
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::Student->value))
            ->whereIn($column, $candidates)
            ->pluck($column)
            ->map(fn ($v) => trim($v))
            ->countBy()
            ->filter(fn ($count) => $count > 1)
            ->keys()
            ->all();
    }

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $student = $this->createStudent->execute($request->user(), $request->validated());

        foreach ($request->validated('course_ids', []) as $courseId) {
            $course = Course::find($courseId);
            if ($course && ($request->user()->can('manageEnrollments', $course) || $request->user()->can('update', $course))) {
                $this->enrollStudent->execute($student, $course);
            }
        }

        $student->load(['roles', 'latestAccessPeriod']);

        // Never log credentials — identity only.
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute('student.create', $student, [
            'student_code' => (string) ($student->student?->student_code ?? ''),
        ]);

        $response = [
            'success' => true,
            'message' => 'Student created.',
            'data' => new StudentResource($student),
        ];

        if (! empty($student->generated_credentials)) {
            $response['credentials'] = $student->generated_credentials;
        }

        return response()->json($response, 201);
    }

    public function show(Request $request, User $student): JsonResponse
    {
        $this->authorize('view', $student);

        $student->load(['roles', 'enrollments.course', 'latestAccessPeriod', 'accessPeriods']);

        return $this->success(new StudentResource($student), 'Student retrieved.');
    }

    public function update(UpdateStudentRequest $request, User $student): JsonResponse
    {
        $this->authorize('update', $student);

        if ($request->has('email')) {
            $this->updateEmail->execute($student, $request->string('email')->toString());
        }

        $account = $this->updateAccount->execute($student, $request->validated());

        return $this->success(new StudentResource($account->load(['roles', 'latestAccessPeriod'])), 'Student updated.');
    }

    public function activate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, true);
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute(
            'student.activate',
            $student,
            ['student_id' => $student->id],
            $request->user(),
            $request,
        );

        return $this->success(new StudentResource($student->load(['roles', 'latestAccessPeriod'])), 'Student activated.');
    }

    public function deactivate(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $student = $this->setActiveState->execute($student, false);
        app(\App\Actions\Audit\RecordAuditLogAction::class)->execute(
            'student.deactivate',
            $student,
            ['student_id' => $student->id],
            $request->user(),
            $request,
        );

        return $this->success(new StudentResource($student->load(['roles', 'latestAccessPeriod'])), 'Student deactivated; history retained.');
    }

    public function suspend(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $reason = $request->input('reason') ?: $request->input('notes');
        $updatedStudent = $this->suspendStudent->execute($request->user(), $student, $reason);

        return $this->success(
            new StudentResource($updatedStudent),
            'Student suspended successfully.'
        );
    }

    public function restore(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $months = $request->integer('months', 1);
        $notes = $request->input('notes');

        $updatedStudent = $this->restoreStudent->execute($request->user(), $student, $months, $notes);

        return $this->success(
            new StudentResource($updatedStudent),
            'Student restored successfully.'
        );
    }

    public function allowImmediately(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $months = $request->integer('months', 1);
        $amount = $request->filled('amount') ? (float) $request->input('amount') : null;
        $notes = $request->input('notes');

        $updatedStudent = $this->allowStudentImmediately->execute(
            $request->user(),
            $student,
            $months,
            $amount,
            $notes
        );

        return $this->success(
            new StudentResource($updatedStudent),
            'Student access allowed immediately.'
        );
    }

    public function resetPassword(ResetPasswordRequest $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $this->resetPassword->execute($student, $request->string('password')->toString());

        return $this->success(null, 'Student password reset.');
    }

    public function resetCredentials(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $result = $this->regenerateCredentials->execute($student);

        return $this->success([
            'student' => new StudentResource($result['student']->load(['roles', 'latestAccessPeriod'])),
            'credentials' => $result['credentials'],
        ], 'Student credentials regenerated successfully.');
    }

    public function renew(Request $request, User $student): JsonResponse
    {
        $this->authorize('manage', $student);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:keep_active,suspend'],
            'months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updatedStudent = $this->renewAccess->execute(
            $request->user(),
            $student,
            $validated['decision'],
            $validated['months'] ?? 1,
            isset($validated['amount']) ? (float) $validated['amount'] : null,
            $validated['notes'] ?? null,
        );

        $message = $validated['decision'] === 'keep_active'
            ? 'Student access kept active.'
            : 'Student access suspended.';

        return $this->success(
            new StudentResource($updatedStudent->load(['roles', 'latestAccessPeriod'])),
            $message
        );
    }

    /**
     * Deactivate selected student accounts without removing their academic or
     * enrollment history. Hard deletion is deliberately not available to teachers.
     */
    public function batchDeactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ]);

        $count = DB::transaction(function () use ($request, $validated): int {
            $students = User::query()
                ->whereIn('id', $validated['ids'])
                ->lockForUpdate()
                ->get();

            foreach ($students as $student) {
                abort_unless($student->isStudent(), 422, 'Only student accounts may be deactivated.');
                $this->authorize('manage', $student);
            }

            foreach ($students as $student) {
                $this->setActiveState->execute($student, false);
                app(\App\Actions\Audit\RecordAuditLogAction::class)->execute(
                    'student.deactivate',
                    $student,
                    ['student_id' => $student->id, 'batch' => true],
                    $request->user(),
                    $request,
                );
            }

            return $students->count();
        });

        return $this->success(
            ['deactivated_count' => $count],
            "{$count} student accounts deactivated; history retained."
        );
    }
}
