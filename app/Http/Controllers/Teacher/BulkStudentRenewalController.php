<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Audit\RecordAuditLogAction;
use App\Actions\Student\RenewStudentAccessAction;
use App\Enums\StudentAccessStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Student\ManagedStudentScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * "Renew all due" in one click: keeps access active, for a number of months,
 * for every student of the staff member whose access is currently
 * "renewal due". Suspended students are never touched.
 *
 * Database work is kept small and bounded: one chunked read of the staff
 * member's students (with their latest access period), one insert per renewed
 * student in a transaction per chunk, and a single batched clean-up of the
 * outstanding renewal notifications.
 */
class BulkStudentRenewalController extends Controller
{
    private const CHUNK = 200;

    public function __construct(
        private readonly ManagedStudentScope $scope,
        private readonly RenewStudentAccessAction $renew,
    ) {
    }

    /** How many students a bulk renewal would renew right now. */
    public function count(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $count = 0;
        $this->eachDueChunk($request->user(), function ($students) use (&$count) {
            $count += $students->count();
        });

        return $this->success(['due_count' => $count], 'Students due for renewal counted.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $validated = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $staff = $request->user();
        $notes = $validated['notes'] ?? 'Bulk renewal by '.$staff->name;
        $amount = isset($validated['amount']) ? (float) $validated['amount'] : null;
        $renewedIds = [];

        $this->eachDueChunk($staff, function ($students) use ($staff, $validated, $amount, $notes, &$renewedIds) {
            DB::transaction(function () use ($students, $staff, $validated, $amount, $notes, &$renewedIds) {
                foreach ($students as $student) {
                    $this->renew->execute($staff, $student, 'keep_active', (int) $validated['months'], $amount, $notes, bulk: true);
                    $renewedIds[] = $student->getKey();
                }
            });
        });

        $this->clearRenewalNotifications($renewedIds);

        app(RecordAuditLogAction::class)->execute('students.bulk_renew', $staff, [
            'renewed' => count($renewedIds),
            'months' => (int) $validated['months'],
        ], $staff);

        return $this->success(
            ['renewed_count' => count($renewedIds), 'months' => (int) $validated['months']],
            'Students renewed.'
        );
    }

    /** Same permission as renewing a single student. */
    private function authorizeStaff(Request $request): void
    {
        $this->authorize('viewAny', User::class);
        abort_unless($request->user()->hasPermissionTo('students.manage'), 403, 'This action is unauthorized.');
    }

    /**
     * Hands the callback the staff member's students whose access is due,
     * a chunk at a time. Only active, non-anonymized accounts qualify; a
     * suspended student is never brought back by this.
     *
     * @param  callable(\Illuminate\Support\Collection<int, User>): void  $callback
     */
    private function eachDueChunk(User $staff, callable $callback): void
    {
        $this->scope->query($staff)
            ->where('is_active', true)
            ->whereNull('anonymized_at')
            ->with('latestAccessPeriod')
            ->chunkById(self::CHUNK, function ($students) use ($callback) {
                $due = $students->filter(
                    fn (User $student) => $student->accessStatus() === StudentAccessStatus::Due->value
                );

                if ($due->isNotEmpty()) {
                    $callback($due);
                }
            });
    }

    /** One pass over the unread renewal notifications, not one per student. */
    private function clearRenewalNotifications(array $studentIds): void
    {
        if ($studentIds === []) {
            return;
        }

        $renewed = array_flip(array_map('intval', $studentIds));

        $ids = DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', 'like', '%StudentRenewalDueNotification%')
            ->get(['id', 'data'])
            ->filter(fn ($n) => isset($renewed[(int) ($n->data['student_id'] ?? 0)]))
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            DatabaseNotification::query()->whereIn('id', $chunk)->update(['read_at' => now()]);
        }
    }
}
