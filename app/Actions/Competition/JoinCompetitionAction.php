<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionParticipantStatus;
use App\Exceptions\CompetitionCapacityFullException;
use App\Exceptions\CompetitionNotAccessibleException;
use App\Exceptions\DuplicateCompetitionParticipationException;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a student for a competition.
 *
 * The server is authoritative for every eligibility and capacity rule:
 *  - the competition must be active (and within its scheduling window),
 *  - the student must be enrolled in the linked exam's course,
 *  - the unique (competition_id, student_id) constraint prevents joining twice,
 *  - capacity is enforced inside a transaction with a row lock so concurrent
 *    join requests cannot exceed max_participants.
 */
class JoinCompetitionAction
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function execute(User $student, Competition $competition): CompetitionParticipant
    {
        $competition->load('exam');
        $competition->lazyFinalize();

        $this->assertAccepting($competition, $student);

        try {
            return DB::transaction(function () use ($student, $competition) {
                // Lock the competition row so capacity checks are serialized.
                $locked = Competition::query()
                    ->lockForUpdate()
                    ->with('exam')
                    ->find($competition->getKey());

                $this->assertAccepting($locked, $student);

                $count = CompetitionParticipant::query()
                    ->where('competition_id', $locked->getKey())
                    ->count();

                if ($locked->max_participants !== null && $count >= $locked->max_participants) {
                    throw new CompetitionCapacityFullException();
                }

                return CompetitionParticipant::create([
                    'competition_id' => $locked->getKey(),
                    'student_id' => $student->getKey(),
                    'joined_at' => now(),
                    'status' => CompetitionParticipantStatus::Registered->value,
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new DuplicateCompetitionParticipationException();
            }

            throw $e;
        }
    }

    private function assertAccepting(Competition $competition, User $student): void
    {
        if (! $competition->areParticipantsAccepted()) {
            throw new CompetitionNotAccessibleException('This competition is not currently accepting participants.');
        }

        if (! $this->enrollments->isEnrolled($student, $competition->exam->course_id)) {
            throw new CompetitionNotAccessibleException('You must be enrolled in the course to participate.');
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return in_array($sqlState, ['23000', '23505'], true)
            || (int) $driverCode === 1062
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'unique constraint');
    }
}
