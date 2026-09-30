<?php

namespace App\Http\Controllers\Student;

use App\Actions\Integrity\RecordIntegrityEventAction;
use App\Enums\IntegrityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordIntegrityEventRequest;
use App\Models\ExamAttempt;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class IntegrityController extends Controller
{
    public function __construct(
        private readonly RecordIntegrityEventAction $recordEvent,
    ) {
    }

    public function store(RecordIntegrityEventRequest $request, ExamAttempt $attempt): JsonResponse
    {
        $type = IntegrityEventType::from($request->validated('event_type'));

        $occurredAt = $request->filled('occurred_at')
            ? Carbon::parse($request->validated('occurred_at'))
            : null;

        $result = $this->recordEvent->execute(
            $attempt,
            $type,
            $occurredAt,
            $request->validated('metadata', [])
        );

        // The student receives an acknowledgement plus the WARNING state of the
        // interruption policy (so the UI can show "Warning N/M"). Risk points,
        // severity and the integrity status stay server-controlled and are
        // never exposed here.
        return $this->success([
            'recorded' => $result['event'] !== null,
            'deduplicated' => $result['deduplicated'],
            'counted' => (bool) $result['counted'],
            'warning_count' => $result['warning_count'],
            'warning_threshold' => $result['warning_threshold'],
            'remaining_warnings' => max(0, $result['warning_threshold'] - $result['warning_count']),
            'terminated' => $result['terminated'],
        ], 'Integrity event recorded.', 201);
    }
}
