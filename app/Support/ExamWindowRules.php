<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;

/**
 * Shared validation for the optional exam window (starts_at / ends_at).
 *
 * Rules:
 *  - both or neither (partial windows are rejected as ambiguous),
 *  - starts_at strictly before ends_at,
 *  - both must be parseable dates (the `date` rule reports malformed input
 *    first, so we never parse an already-invalid value here).
 *
 * Timestamps are interpreted in the application timezone (UTC, config/app.php).
 */
final class ExamWindowRules
{
    /**
     * @param  array<string, mixed>  $input  validated-or-raw request input
     * @param  array<string, mixed>  $existing  current model values, used for partial updates
     */
    public static function validate(array $input, Validator $validator, array $existing = []): void
    {
        // The `date` rule owns malformed-input reporting; do not double-report
        // and do not attempt to parse values it already rejected.
        if ($validator->errors()->has('starts_at') || $validator->errors()->has('ends_at')) {
            return;
        }

        $starts = array_key_exists('starts_at', $input) ? $input['starts_at'] : ($existing['starts_at'] ?? null);
        $ends = array_key_exists('ends_at', $input) ? $input['ends_at'] : ($existing['ends_at'] ?? null);

        $hasStarts = filled($starts);
        $hasEnds = filled($ends);

        if ($hasStarts !== $hasEnds) {
            $validator->errors()->add(
                'starts_at',
                'An exam window requires both a start and an end time, or neither.'
            );

            return;
        }

        if (! $hasStarts || ! $hasEnds) {
            return;
        }

        try {
            $start = Carbon::parse($starts);
            $end = Carbon::parse($ends);
        } catch (\Throwable) {
            return;
        }

        if (! $start->lessThan($end)) {
            $validator->errors()->add('ends_at', 'The exam end time must be after the start time.');
        }
    }
}
