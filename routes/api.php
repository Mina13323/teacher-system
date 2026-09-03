<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
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

// ---- Teacher course management --------------------------------------------
Route::prefix('teacher')->middleware(['auth:sanctum'])->group(function () {
    Route::get('courses', [TeacherCourseController::class, 'index']);
    Route::post('courses', [TeacherCourseController::class, 'store']);
    Route::get('courses/{course}', [TeacherCourseController::class, 'show']);
    Route::put('courses/{course}', [TeacherCourseController::class, 'update']);
    Route::delete('courses/{course}', [TeacherCourseController::class, 'destroy']);
});
