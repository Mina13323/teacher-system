<?php

namespace App\Enums;

/**
 * Aggregate state of a unit or course within the student roadmap.
 */
enum RoadmapStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
