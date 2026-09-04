<?php

namespace App\Http\Controllers\Student;

use App\Actions\Competition\JoinCompetitionAction;
use App\Actions\Competition\RecalculateCompetitionLeaderboardAction;
use App\Enums\CompetitionStatus;
use App\Exceptions\CompetitionNotAccessibleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaderboardResource;
use App\Http\Resources\StudentCompetitionResource;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompetitionController extends Controller
{
    public function __construct(
        private readonly JoinCompetitionAction $joinCompetition,
        private readonly RecalculateCompetitionLeaderboardAction $recalculateLeaderboard,
        private readonly EnrollmentService $enrollments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $student = $request->user();

        // A student may only discover competitions whose linked exam belongs to a
        // course they are actively enrolled in — consistent with show/join.
        $enrolledCourseIds = $this->enrollments->enrolledCourseIds($student);

        $competitions = Competition::query()
            ->with('exam')
            ->withCount('participants')
            ->whereHas('exam', fn ($q) => $q->whereIn('course_id', $enrolledCourseIds))
            ->whereIn('status', [CompetitionStatus::Published->value, CompetitionStatus::Active->value])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->latest()
            ->get();

        $joinedIds = CompetitionParticipant::query()
            ->where('student_id', $student->getKey())
            ->pluck('competition_id')
            ->all();

        $competitions->each(function (Competition $competition) use ($joinedIds) {
            // Resolve the lifecycle consistently with show/join/leaderboard so a
            // published competition whose window has opened is shown as active.
            $competition->lazyFinalize();
            $competition->is_joined = in_array($competition->getKey(), $joinedIds, true);
        });

        return $this->success(StudentCompetitionResource::collection($competitions), 'Competitions retrieved.');
    }

    public function show(Request $request, Competition $competition): JsonResponse
    {
        $this->assertViewable($request, $competition);

        $competition->load('exam')->loadCount('participants');
        $competition->is_joined = $this->isJoined($request->user(), $competition);

        return $this->success(new StudentCompetitionResource($competition), 'Competition retrieved.');
    }

    public function join(Request $request, Competition $competition): JsonResponse
    {
        $this->joinCompetition->execute($request->user(), $competition);

        $competition->load('exam')->loadCount('participants');
        $competition->is_joined = true;

        return $this->success(new StudentCompetitionResource($competition), 'Joined competition.', 201);
    }

    public function leaderboard(Request $request, Competition $competition): JsonResponse
    {
        $this->assertParticipant($request, $competition);

        $competition->lazyFinalize();

        if ($competition->status->isActive()) {
            $this->recalculateLeaderboard->execute($competition);
        }

        $leaderboard = $competition->results()
            ->whereHas('participant', fn ($q) => $q->where('status', '!=', 'disqualified'))
            ->with('participant.student')
            ->orderBy('rank')
            ->paginate($this->perPage($request));

        return $this->success(LeaderboardResource::collection($leaderboard), 'Leaderboard retrieved.');
    }

    public function me(Request $request, Competition $competition): JsonResponse
    {
        $participant = $this->assertParticipant($request, $competition);

        $competition->lazyFinalize();

        if ($competition->status->isActive()) {
            $this->recalculateLeaderboard->execute($competition);
        }

        // total_participants is the number of currently registered participants
        // (non-disqualified), which is the widest, least-surprising denominator.
        $total = CompetitionParticipant::query()
            ->where('competition_id', $competition->getKey())
            ->where('status', '!=', 'disqualified')
            ->count();

        // Query the result fresh after recalculation has persisted the rank.
        $result = $competition->results()
            ->where('participant_id', $participant->getKey())
            ->first();

        return $this->success([
            'rank' => $result?->rank,
            'score' => $result?->score,
            'percentage' => $result?->percentage,
            'completion_time' => $result?->completion_time,
            'qualified' => $result?->qualified,
            'total_participants' => $total,
        ], 'Your position retrieved.');
    }

    private function assertViewable(Request $request, Competition $competition): void
    {
        $competition->lazyFinalize();

        if ($competition->status->isDraft() || $competition->status->isArchived()) {
            abort(404, 'Competition not found.');
        }

        if (! $this->enrollments->isEnrolled($request->user(), $competition->exam->course_id)) {
            throw new CompetitionNotAccessibleException('You must be enrolled in the course to participate.');
        }
    }

    private function isJoined($user, Competition $competition): bool
    {
        return CompetitionParticipant::query()
            ->where('competition_id', $competition->getKey())
            ->where('student_id', $user->getKey())
            ->exists();
    }

    private function assertParticipant(Request $request, Competition $competition): CompetitionParticipant
    {
        $competition->lazyFinalize();

        $participant = CompetitionParticipant::query()
            ->where('competition_id', $competition->getKey())
            ->where('student_id', $request->user()->getKey())
            ->first();

        if (! $participant) {
            throw new CompetitionNotAccessibleException('You must join this competition to view the leaderboard.');
        }

        return $participant;
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
