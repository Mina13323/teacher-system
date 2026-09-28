<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\StudentRegistrationLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StudentRegistrationLinkController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $link = $this->linkFor($this->owner($request->user()));

        return $this->success($this->payload($link), 'Student registration link retrieved.');
    }

    public function rotate(Request $request): JsonResponse
    {
        $link = $this->linkFor($this->owner($request->user()));
        $token = Str::random(48);
        $link->update(['token' => $token, 'token_hash' => hash('sha256', $token), 'is_active' => true]);

        return $this->success($this->payload($link->fresh()), 'Student registration link renewed.');
    }

    public function toggle(Request $request): JsonResponse
    {
        $link = $this->linkFor($this->owner($request->user()));
        $link->update(['is_active' => $request->boolean('is_active')]);

        return $this->success($this->payload($link->fresh()), 'Student registration link updated.');
    }

    private function owner(User $user): User
    {
        if (config('app.co_teaching', false) && $user->isTeacher()) {
            $primaryTeacher = User::role(UserRole::Teacher->value)->orderBy('id')->first();
            if ($primaryTeacher) {
                return $primaryTeacher;
            }
        }

        return $user->isAssistant() && $user->created_by
            ? User::findOrFail($user->created_by)
            : $user;
    }

    private function linkFor(User $teacher): StudentRegistrationLink
    {
        $token = Str::random(48);

        return StudentRegistrationLink::firstOrCreate(
            ['teacher_id' => $teacher->getKey()],
            ['token' => $token, 'token_hash' => hash('sha256', $token), 'is_active' => true],
        );
    }

    private function payload(StudentRegistrationLink $link): array
    {
        return [
            'url' => rtrim(config('app.url'), '/').'/join/'.$link->token,
            'is_active' => $link->is_active,
        ];
    }
}
