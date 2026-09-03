<?php

namespace Database\Factories;

use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamIntegritySetting>
 */
class ExamIntegritySettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
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
