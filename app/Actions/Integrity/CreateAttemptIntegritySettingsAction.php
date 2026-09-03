<?php

namespace App\Actions\Integrity;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptIntegritySetting;
use App\Services\Integrity\IntegrityRiskConfig;

/**
 * Freezes the exam's integrity configuration onto an attempt at the moment the
 * attempt starts. Later teacher changes to the live exam integrity settings do
 * not affect this attempt.
 */
class CreateAttemptIntegritySettingsAction
{
    public function __construct(
        private readonly IntegrityRiskConfig $riskConfig,
    ) {
    }

    public function execute(ExamAttempt $attempt, Exam $exam): ExamAttemptIntegritySetting
    {
        $exam->load('integritySetting');

        $settings = $exam->integritySetting;

        $values = $settings?->only([
            'fullscreen_required',
            'prevent_copy',
            'prevent_paste',
            'prevent_context_menu',
            'detect_tab_switch',
            'detect_window_blur',
            'detect_keyboard_shortcuts',
        ]) ?? $this->riskConfig->defaults();

        return ExamAttemptIntegritySetting::create(array_merge(
            ['attempt_id' => $attempt->getKey()],
            $values
        ));
    }
}
