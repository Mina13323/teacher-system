<?php

namespace Database\Factories;

use App\Enums\IntegrityEventType;
use App\Enums\IntegritySeverity;
use App\Models\ExamAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamIntegrityEvent>
 */
class ExamIntegrityEventFactory extends Factory
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
            'event_type' => IntegrityEventType::TabSwitch->value,
            'occurred_at' => now(),
            'metadata' => null,
            'severity' => IntegritySeverity::Low->value,
            'risk_points' => 0,
        ];
    }
}
