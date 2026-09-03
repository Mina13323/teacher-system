<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\EnrollmentController;
use App\Http\Controllers\Student\ProgressController;
use App\Http\Controllers\Student\RoadmapController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
use App\Http\Controllers\Teacher\DashboardController as TeacherDashboardController;
use App\Http\Controllers\Teacher\LessonController as TeacherLessonController;
use App\Http\Controllers\Teacher\UnitController as TeacherUnitController;
use App\Http\Controllers\Teacher\VideoController as TeacherVideoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Mounted by bootstrap/app.php under the "/api/v1" prefix. Keep every route
| here thin; business logic lives in Actions/Services and authorization is
| enforced server-side via Policies.
|
*/

// ---- Authentication -------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// ---- Public course browsing (published only) -------------------------------
Route::get('courses', [CourseController::class, 'index']);
Route::get('courses/{course}', [CourseController::class, 'show']);

// ---- Teacher / Admin: course management + content nesting -------------------
Route::prefix('teacher')->middleware(['auth:sanctum'])->group(function () {
    Route::get('dashboard', [TeacherDashboardController::class, 'index']);

    Route::get('courses', [TeacherCourseController::class, 'index']);
    Route::post('courses', [TeacherCourseController::class, 'store']);
    Route::get('courses/{course}', [TeacherCourseController::class, 'show']);
    Route::put('courses/{course}', [TeacherCourseController::class, 'update']);
    Route::patch('courses/{course}/publish', [TeacherCourseController::class, 'publish']);
    Route::patch('courses/{course}/unpublish', [TeacherCourseController::class, 'unpublish']);
    Route::delete('courses/{course}', [TeacherCourseController::class, 'destroy']);

    // Units (nested under a course)
    Route::get('courses/{course}/units', [TeacherUnitController::class, 'index']);
    Route::post('courses/{course}/units', [TeacherUnitController::class, 'store']);
    Route::put('courses/{course}/units/reorder', [TeacherUnitController::class, 'reorder']);
    Route::get('units/{unit}', [TeacherUnitController::class, 'show']);
    Route::put('units/{unit}', [TeacherUnitController::class, 'update']);
    Route::delete('units/{unit}', [TeacherUnitController::class, 'destroy']);

    // Lessons (nested under a unit)
    Route::get('units/{unit}/lessons', [TeacherLessonController::class, 'index']);
    Route::post('units/{unit}/lessons', [TeacherLessonController::class, 'store']);
    Route::put('units/{unit}/lessons/reorder', [TeacherLessonController::class, 'reorder']);
    Route::get('lessons/{lesson}', [TeacherLessonController::class, 'show']);
    Route::put('lessons/{lesson}', [TeacherLessonController::class, 'update']);
    Route::patch('lessons/{lesson}/publish', [TeacherLessonController::class, 'publish']);
    Route::patch('lessons/{lesson}/unpublish', [TeacherLessonController::class, 'unpublish']);
    Route::delete('lessons/{lesson}', [TeacherLessonController::class, 'destroy']);

    // Videos (nested under a lesson)
    Route::get('lessons/{lesson}/videos', [TeacherVideoController::class, 'index']);
    Route::post('lessons/{lesson}/videos', [TeacherVideoController::class, 'store']);
    Route::put('lessons/{lesson}/videos/reorder', [TeacherVideoController::class, 'reorder']);
    Route::get('videos/{video}', [TeacherVideoController::class, 'show']);
    Route::put('videos/{video}', [TeacherVideoController::class, 'update']);
    Route::patch('videos/{video}/publish', [TeacherVideoController::class, 'publish']);
    Route::patch('videos/{video}/unpublish', [TeacherVideoController::class, 'unpublish']);
    Route::delete('videos/{video}', [TeacherVideoController::class, 'destroy']);
});

// ---- Student: enrollment, access, progress, roadmap, dashboard --------------
Route::prefix('student')->middleware(['auth:sanctum'])->group(function () {
    Route::get('dashboard', [StudentDashboardController::class, 'index']);

    Route::get('courses', [EnrollmentController::class, 'index']);
    Route::post('courses/{course}/enroll', [EnrollmentController::class, 'store']);
    Route::get('courses/{course}', [EnrollmentController::class, 'show']);
    Route::get('courses/{course}/roadmap', [RoadmapController::class, 'show']);

    Route::get('progress', [ProgressController::class, 'index']);
    Route::get('lessons/{lesson}/progress', [ProgressController::class, 'show']);
    Route::put('lessons/{lesson}/progress', [ProgressController::class, 'store']);
});
