<?php

namespace App\Http\Controllers\Teacher;

use App\Actions\Competition\ArchiveCompetitionAction;
use App\Actions\Competition\CreateCompetitionAction;
use App\Actions\Competition\DeleteCompetitionAction;
use App\Actions\Competition\DisqualifyCompetitionParticipantAction;
use App\Actions\Competition\PublishCompetitionAction;
use App\Actions\Competition\RecalculateCompetitionLeaderboardAction;
use App\Actions\Competition\UpdateCompetitionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCompetitionRequest;
use App\Http\Requests\UpdateCompetitionRequest;
use App\Http\Resources\CompetitionParticipantResource;
use App\Http\Resources\LeaderboardResource;
use App\Http\Resources\TeacherCompetitionResource;
use App\Models\Competition;
use App\Models\CompetitionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompetitionController extends Controller
{
    public function __construct(
        private readonly CreateCompetitionAction $createCompetition,
        private readonly UpdateCompetitionAction $updateCompetition,
        private readonly DeleteCompetitionAction $deleteCompetition,
        private readonly PublishCompetitionAction $publishCompetition,
        private readonly ArchiveCompetitionAction $archiveCompetition,
        private readonly RecalculateCompetitionLeaderboardAction $recalculateLeaderboard,
        private readonly DisqualifyCompetitionParticipantAction $disqualifyParticipant,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Competition::class);

        $query = Competition::query()
            ->with(['creator', 'exam'])
            ->withCount(['participants', 'results']);

        if (! $request->user()->isAdmin()) {
            $query->where('created_by', $request->user()->getKey());
        }

        $competitions = $query->latest()->paginate($this->perPage($request));

        // Resolve the lifecycle consistently with show/join/leaderboard so the
        // listing never reports a misleading status for a competition whose
        // scheduling window has already advanced.
        $competitions->getCollection()->each(fn (Competition $competition) => $competition->lazyFinalize());

        return $this->success(TeacherCompetitionResource::collection($competitions), 'Competitions retrieved.');
    }

    public function store(CreateCompetitionRequest $request): JsonResponse
    {
        $competition = $this->createCompetition->execute($request->user(), $request->validated());

        return $this->success(
            new TeacherCompetitionResource($competition->load(['creator', 'exam'])->loadCount(['participants', 'results'])),
            'Competition created.',
            201
        );
    }

    public function show(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('view', $competition);

        $competition->lazyFinalize()->load(['creator', 'exam'])->loadCount(['participants', 'results']);

        return $this->success(new TeacherCompetitionResource($competition), 'Competition retrieved.');
    }

    public function update(UpdateCompetitionRequest $request, Competition $competition): JsonResponse
    {
        $competition = $this->updateCompetition->execute($competition, $request->validated());

        return $this->success(
            new TeacherCompetitionResource($competition->load(['creator', 'exam'])->loadCount(['participants', 'results'])),
            'Competition updated.'
        );
    }

    public function publish(Competition $competition): JsonResponse
    {
        $this->authorize('manage', $competition);

        $competition = $this->publishCompetition->execute($competition);

        return $this->success(
            new TeacherCompetitionResource($competition->load(['creator', 'exam'])->loadCount(['participants', 'results'])),
            'Competition published.'
        );
    }

    public function archive(Competition $competition): JsonResponse
    {
        $this->authorize('manage', $competition);

        $competition = $this->archiveCompetition->execute($competition);

        return $this->success(
            new TeacherCompetitionResource($competition->load(['creator', 'exam'])->loadCount(['participants', 'results'])),
            'Competition archived.'
        );
    }

    public function destroy(Competition $competition): JsonResponse
    {
        $this->authorize('delete', $competition);

        $this->deleteCompetition->execute($competition);

        return $this->success(null, 'Competition deleted.');
    }

    public function participants(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('manage', $competition);

        $competition->lazyFinalize();

        $participants = $competition->participants()
            ->with(['student', 'result.attempt'])
            ->latest('joined_at')
            ->paginate($this->perPage($request));

        return $this->success(CompetitionParticipantResource::collection($participants), 'Participants retrieved.');
    }

    public function leaderboard(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('manage', $competition);

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

    public function recalculate(Competition $competition): JsonResponse
    {
        $this->authorize('manage', $competition);

        $this->recalculateLeaderboard->execute($competition);

        return $this->success(null, 'Competition leaderboard recalculated.');
    }

    public function disqualify(Competition $competition, CompetitionParticipant $participant): JsonResponse
    {
        $this->authorize('manage', $competition);

        abort_unless($participant->competition_id === $competition->getKey(), 404, 'Participant not found.');

        $participant = $this->disqualifyParticipant->execute($participant);

        return $this->success(
            new CompetitionParticipantResource($participant->load('student')),
            'Participant disqualified.'
        );
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 20) > 0
            ? min(100, $request->integer('per_page', 20))
            : 20;
    }
}
