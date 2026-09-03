<?php

namespace App\Enums;

/**
 * Integrity status of an exam attempt.
 *
 *   NORMAL     No meaningful suspicious activity.
 *   MONITORING Some integrity events detected; below the flagged threshold.
 *   FLAGGED    Risk threshold exceeded; teacher review recommended.
 *   REVIEWED   Teacher has reviewed the attempt.
 *   CLEARED    Teacher reviewed and determined no integrity violation.
 *
 * The automatic status is derived from the sum of event risk points; the
 * teacher's review decision (CLEARED/FLAGGED) is a separate, human decision and
 * is never overwritten by the automatic calculation.
 */
enum IntegrityStatus: string
{
    case Normal = 'normal';
    case Monitoring = 'monitoring';
    case Flagged = 'flagged';
    case Reviewed = 'reviewed';
    case Cleared = 'cleared';
}
