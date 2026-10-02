<?php

namespace App\Enums;

enum ExamMakeUpAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Used = 'used';
    case Revoked = 'revoked';
}
