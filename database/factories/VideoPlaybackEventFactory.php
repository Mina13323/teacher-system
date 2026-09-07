<?php

namespace Database\Factories;

use App\Enums\VideoPlaybackEventType;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VideoPlaybackEvent>
 */
class VideoPlaybackEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'student_id' => User::factory(),
            'session_id' => null,
            'event_type' => VideoPlaybackEventType::PlaybackGranted->value,
            'occurred_at' => now(),
        ];
    }
}
