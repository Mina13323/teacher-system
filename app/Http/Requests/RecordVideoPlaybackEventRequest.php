<?php

namespace App\Http\Requests;

use App\Enums\VideoPlaybackEventType;
use App\Enums\UserRole;
use App\Models\Video;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordVideoPlaybackEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only a student (or admin) may report playback protection detections.
        // The owning/video scoping is enforced in the Action.
        return $this->user() !== null
            && ($this->user()->hasRole(UserRole::Student->value) || $this->user()->hasRole(UserRole::Admin->value));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Only client-reportable, browser-observable detections are accepted.
        // Server-authoritative states are recorded by the backend and rejected here.
        $allowed = array_map(
            fn (VideoPlaybackEventType $type) => $type->value,
            VideoPlaybackEventType::clientReportable()
        );

        return [
            'session_token' => ['required', 'string', 'max:128'],
            'event_type' => ['required', 'string', Rule::in($allowed)],
        ];
    }
}
