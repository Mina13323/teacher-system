<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Admin\TeacherController as AdminTeacherController;
use App\Http\Controllers\Student\DashboardController as StudentDashboardController;
use App\Http\Controllers\Student\AnalyticsController as StudentAnalyticsController;
use App\Http\Controllers\Student\AttemptController as StudentAttemptController;
use App\Http\Controllers\Student\EnrollmentController;
use App\Http\Controllers\Student\ExamController as StudentExamController;
use App\Http\Controllers\Student\CompetitionController as StudentCompetitionController;
use App\Http\Controllers\Student\IntegrityController as StudentIntegrityController;
use App\Http\Controllers\Student\ProgressController;
use App\Http\Controllers\Student\RoadmapController;
use App\Http\Controllers\Student\VideoController as StudentVideoController;
use App\Http\Controllers\Teacher\AttemptController as TeacherAttemptController;
use App\Http\Controllers\Teacher\CompetitionController as TeacherCompetitionController;
use App\Http\Controllers\Teacher\CourseController as TeacherCourseController;
use App\Http\Controllers\Teacher\IntegrityController as TeacherIntegrityController;
use App\Http\Controllers\Teacher\DashboardController as TeacherDashboardController;
use App\Http\Controllers\Teacher\ExamController as TeacherExamController;
use App\Http\Controllers\Teacher\LessonController as TeacherLessonController;
use App\Http\Controllers\Teacher\OptionController as TeacherOptionController;
use App\Http\Controllers\Teacher\QuestionController as TeacherQuestionController;
use App\Http\Controllers\Teacher\UnitController as TeacherUnitController;
use App\Http\Controllers\Teacher\VideoController as TeacherVideoController;
use App\Http\Controllers\Teacher\StudentController as TeacherStudentController;
use App\Http\Controllers\Teacher\CourseStudentController as TeacherCourseStudentController;
use App\Http\Controllers\Teacher\AnalyticsController as TeacherAnalyticsController;
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
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('password', [ProfileController::class, 'changePassword']);
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

    // Exams (nested under a course) + question/option management
    Route::get('courses/{course}/exams', [TeacherExamController::class, 'index']);
    Route::post('courses/{course}/exams', [TeacherExamController::class, 'store']);
    Route::get('exams/{exam}', [TeacherExamController::class, 'show']);
    Route::put('exams/{exam}', [TeacherExamController::class, 'update']);
    Route::post('exams/{exam}/publish', [TeacherExamController::class, 'publish']);
    Route::post('exams/{exam}/archive', [TeacherExamController::class, 'archive']);
    Route::delete('exams/{exam}', [TeacherExamController::class, 'destroy']);

    Route::get('exams/{exam}/attempts', [TeacherExamController::class, 'attempts']);
    Route::get('attempts/{attempt}', [TeacherAttemptController::class, 'show']);

    // Exam integrity configuration + attempt integrity review
    Route::get('exams/{exam}/integrity', [TeacherIntegrityController::class, 'showSettings']);
    Route::put('exams/{exam}/integrity', [TeacherIntegrityController::class, 'updateSettings']);
    Route::get('attempts/{attempt}/integrity', [TeacherIntegrityController::class, 'showAttemptIntegrity']);
    Route::get('attempts/{attempt}/integrity-events', [TeacherIntegrityController::class, 'indexAttemptEvents']);
    Route::post('attempts/{attempt}/integrity/review', [TeacherIntegrityController::class, 'review']);

    Route::get('exams/{exam}/questions', [TeacherQuestionController::class, 'index']);
    Route::post('exams/{exam}/questions', [TeacherQuestionController::class, 'store']);
    Route::get('questions/{question}', [TeacherQuestionController::class, 'show']);
    Route::put('questions/{question}', [TeacherQuestionController::class, 'update']);
    Route::delete('questions/{question}', [TeacherQuestionController::class, 'destroy']);

    Route::get('questions/{question}/options', [TeacherOptionController::class, 'index']);
    Route::post('questions/{question}/options', [TeacherOptionController::class, 'store']);
    Route::put('options/{option}', [TeacherOptionController::class, 'update']);
    Route::delete('options/{option}', [TeacherOptionController::class, 'destroy']);

    // Competitions (separate domain, owned by the creating teacher)
    Route::get('competitions', [TeacherCompetitionController::class, 'index']);
    Route::post('competitions', [TeacherCompetitionController::class, 'store']);
    Route::get('competitions/{competition}', [TeacherCompetitionController::class, 'show']);
    Route::put('competitions/{competition}', [TeacherCompetitionController::class, 'update']);
    Route::delete('competitions/{competition}', [TeacherCompetitionController::class, 'destroy']);
    Route::post('competitions/{competition}/publish', [TeacherCompetitionController::class, 'publish']);
    Route::post('competitions/{competition}/archive', [TeacherCompetitionController::class, 'archive']);
    Route::get('competitions/{competition}/participants', [TeacherCompetitionController::class, 'participants']);
    Route::get('competitions/{competition}/leaderboard', [TeacherCompetitionController::class, 'leaderboard']);
    Route::post('competitions/{competition}/recalculate-leaderboard', [TeacherCompetitionController::class, 'recalculate']);
    Route::post('competitions/{competition}/participants/{participant}/disqualify', [TeacherCompetitionController::class, 'disqualify']);

    // Student account management (teacher-owned LMS)
    Route::get('students', [TeacherStudentController::class, 'index']);
    Route::post('students', [TeacherStudentController::class, 'store']);
    Route::get('students/{student}', [TeacherStudentController::class, 'show']);
    Route::put('students/{student}', [TeacherStudentController::class, 'update']);
    Route::patch('students/{student}/activate', [TeacherStudentController::class, 'activate']);
    Route::patch('students/{student}/deactivate', [TeacherStudentController::class, 'deactivate']);
    Route::post('students/{student}/reset-password', [TeacherStudentController::class, 'resetPassword']);
    Route::post('students/{student}/notify', [NotificationController::class, 'sendMessage']);

    // Course enrollment management (teacher enrolls/manages students in courses)
    Route::get('courses/{course}/students', [TeacherCourseStudentController::class, 'index']);
    Route::post('courses/{course}/students', [TeacherCourseStudentController::class, 'store']);
    Route::delete('courses/{course}/students/{student}', [TeacherCourseStudentController::class, 'destroy']);

    // Analytics & reporting
    Route::get('analytics/overview', [TeacherAnalyticsController::class, 'overview']);
    Route::get('analytics/courses/{course}', [TeacherAnalyticsController::class, 'courseAnalytics']);
    Route::get('analytics/students/{student}', [TeacherAnalyticsController::class, 'studentAnalytics']);
});

// ---- Admin: system & account oversight --------------------------------------
Route::prefix('admin')->middleware(['auth:sanctum'])->group(function () {
    Route::get('dashboard', [AdminDashboardController::class, 'index']);
    Route::get('teachers', [AdminTeacherController::class, 'index']);
    Route::post('teachers', [AdminTeacherController::class, 'store']);
    Route::get('teachers/{teacher}', [AdminTeacherController::class, 'show']);
    Route::put('teachers/{teacher}', [AdminTeacherController::class, 'update']);
    Route::patch('teachers/{teacher}/activate', [AdminTeacherController::class, 'activate']);
    Route::patch('teachers/{teacher}/deactivate', [AdminTeacherController::class, 'deactivate']);
    Route::post('teachers/{teacher}/reset-password', [AdminTeacherController::class, 'resetPassword']);
    Route::get('students', [AdminStudentController::class, 'index']);
    Route::get('students/{student}', [AdminStudentController::class, 'show']);
    Route::put('students/{student}', [AdminStudentController::class, 'update']);
    Route::patch('students/{student}/activate', [AdminStudentController::class, 'activate']);
    Route::patch('students/{student}/deactivate', [AdminStudentController::class, 'deactivate']);
    Route::post('students/{student}/reset-password', [AdminStudentController::class, 'resetPassword']);
    Route::get('students/{student}/analytics', [AdminStudentController::class, 'analytics']);
});

// ---- Notifications (any authenticated user) ----------------------------------
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
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

    // Video content access (published, enrolled-course only).
    Route::get('lessons/{lesson}/videos', [StudentVideoController::class, 'index']);
    // Protected playback: only place a student receives a playable reference.
    Route::get('videos/{video}/playback', [StudentVideoController::class, 'playback']);

    // Exams: discovery, attempts, answering, submission
    Route::get('exams', [StudentExamController::class, 'index']);
    Route::get('exams/{exam}', [StudentExamController::class, 'show']);
    Route::get('exams/{exam}/attempts', [StudentExamController::class, 'attempts']);
    Route::post('exams/{exam}/start', [StudentExamController::class, 'start']);

    Route::get('attempts/{attempt}', [StudentAttemptController::class, 'show']);
    Route::post('attempts/{attempt}/answers', [StudentAttemptController::class, 'answer']);
    Route::post('attempts/{attempt}/submit', [StudentAttemptController::class, 'submit']);

    // Integrity event recording (rate limited)
    Route::post('attempts/{attempt}/integrity-events', [StudentIntegrityController::class, 'store'])
        ->middleware('throttle:integrity-events');

    // Competitions (discovery, participation, leaderboard)
    Route::get('competitions', [StudentCompetitionController::class, 'index']);
    Route::get('competitions/{competition}', [StudentCompetitionController::class, 'show']);
    Route::post('competitions/{competition}/join', [StudentCompetitionController::class, 'join']);
    Route::get('competitions/{competition}/leaderboard', [StudentCompetitionController::class, 'leaderboard']);
    Route::get('competitions/{competition}/leaderboard/me', [StudentCompetitionController::class, 'me']);

    // Student's own analytics
    Route::get('analytics/me', [StudentAnalyticsController::class, 'me']);
});
