<?php

namespace App\Actions\Competition;

use App\Enums\CompetitionStatus;
use App\Enums\EnrollmentStatus;
use App\Exceptions\InvalidCompetitionStateException;
use App\Models\Competition;
use App\Models\Enrollment;
use App\Notifications\CompetitionPublishedNotification;

/**
 * Publishes a competition, moving it from DRAFT to PUBLISHED.
 *
 * A competition must have a valid scheduling window before it can be published
 * (a start time and an end time that is strictly after the start). The status
 * transition is validated against the linear lifecycle and can only be DRAFT ->
 * PUBLISHED.
 */
class PublishCompetitionAction
{
    public function execute(Competition $competition): Competition
    {
        if ($competition->starts_at === null) {
            throw new InvalidCompetitionStateException('A start time is required to publish this competition.');
        }

        if ($competition->ends_at === null) {
            throw new InvalidCompetitionStateException('An end time is required to publish this competition.');
        }

        if ($competition->ends_at->lte($competition->starts_at)) {
            throw new InvalidCompetitionStateException('The end time must be after the start time.');
        }

        if (! $competition->status->canTransitionTo(CompetitionStatus::Published)) {
            throw new InvalidCompetitionStateException(
                'This competition cannot be published from its current state.'
            );
        }

        $competition->status = CompetitionStatus::Published->value;
        $competition->save();

        $this->notifyEnrolledStudents($competition->load('exam'));

        return $competition->fresh();
    }

    /**
     * Notify enrolled students (in the linked exam's course) that the
     * competition is open. Contains no scoring/ranking data.
     */
    private function notifyEnrolledStudents(Competition $competition): void
    {
        if (! $competition->exam) {
            return;
        }

        Enrollment::query()
            ->where('course_id', $competition->exam->course_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->with('student')
            ->get()
            ->each(function (Enrollment $enrollment) use ($competition) {
                $enrollment->student?->notify(new CompetitionPublishedNotification($competition));
            });
    }
}
