<?php

namespace App\Actions\Integrity;

use App\Models\Exam;
use App\Models\ExamIntegritySetting;

/**
 * Creates or updates an exam's integrity configuration (one row per exam).
 * Only the teacher who manages the exam should invoke this.
 *
 * The live settings are NOT copied into any attempt; instead, when an attempt
 * starts, the settings in force at that moment are frozen onto the attempt so a
 * later teacher change never silently changes the rules of an existing attempt.
 */
class AssignExamIntegritySettingsAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Exam $exam, array $data): ExamIntegritySetting
    {
        $settings = $exam->integritySetting()
            ->lockForUpdate()
            ->first() ?? new ExamIntegritySetting(['exam_id' => $exam->getKey()]);

        $settings->fill($data);
        $settings->save();

        return $settings->fresh();
    }
}
