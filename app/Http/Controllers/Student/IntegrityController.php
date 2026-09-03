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

        // The student only receives an acknowledgement. Risk points, severity and
        // the integrity status are server-controlled and never exposed here.
        return $this->success([
            'recorded' => $result['event'] !== null,
            'deduplicated' => $result['deduplicated'],
        ], 'Integrity event recorded.', 201);
    }
}
