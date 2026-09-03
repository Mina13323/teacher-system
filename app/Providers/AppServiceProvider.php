<?php

namespace App\Providers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Unit;
use App\Models\Video;
use App\Policies\CoursePolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\LessonPolicy;
use App\Policies\LessonProgressPolicy;
use App\Policies\UnitPolicy;
use App\Policies\VideoPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API rate limiter used by the "throttle:api" middleware.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('api.rate_limit.default'))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Admins are allowed every capability via a Gate "before" hook.
        Gate::before(function ($user, $ability) {
            if ($user) {
                return $user->hasRole('admin') ? true : null;
            }

            return null;
        });

        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(Lesson::class, LessonPolicy::class);
        Gate::policy(Video::class, VideoPolicy::class);
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(LessonProgress::class, LessonProgressPolicy::class);
    }
}
