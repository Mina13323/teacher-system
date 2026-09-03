<?php

namespace Database\Factories;

use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamAttemptIntegritySetting>
 */
class ExamAttemptIntegritySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attempt_id' => ExamAttempt::factory(),
            'fullscreen_required' => false,
            'prevent_copy' => false,
            'prevent_paste' => false,
            'prevent_context_menu' => false,
            'detect_tab_switch' => true,
            'detect_window_blur' => true,
            'detect_keyboard_shortcuts' => false,
        ];
    }
}
