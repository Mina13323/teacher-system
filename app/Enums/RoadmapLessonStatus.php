<?php

namespace App\Enums;

/**
 * Enrollment-driven state of a lesson within the student roadmap.
 */
enum RoadmapLessonStatus: string
{
    case Locked = 'locked';
    case Available = 'available';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
