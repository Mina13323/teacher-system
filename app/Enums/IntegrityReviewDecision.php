<?php

namespace App\Enums;

/**
 * Teacher review decision recorded against an attempt's integrity evidence.
 *
 * The decision is a human judgement recorded on top of (and separately from) the
 * raw event evidence; it never mutates the historical event risk points.
 */
enum IntegrityReviewDecision: string
{
    case Cleared = 'CLEARED';
    case Flagged = 'FLAGGED';
}
