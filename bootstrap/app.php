<?php

use App\Exceptions\AccountDisabledException;
use App\Exceptions\AttemptLimitReachedException;
use App\Exceptions\CompetitionCapacityFullException;
use App\Exceptions\CompetitionNotAccessibleException;
use App\Exceptions\CourseNotPublishedException;
use App\Exceptions\DuplicateCompetitionParticipationException;
use App\Exceptions\InvalidCompetitionStateException;
use App\Exceptions\ResourceDeletionBlockedException;
use App\Exceptions\DuplicateEnrollmentException;
use App\Exceptions\ExamNotAccessibleException;
use App\Exceptions\ExamNotPublishedException;
use App\Exceptions\ExamNotReadyToPublishException;
use App\Exceptions\ExamStructureLockedException;
use App\Exceptions\ExamWindowClosedException;
use App\Exceptions\ExamWindowNotOpenException;
use App\Exceptions\InvalidAttemptStateException;
use App\Exceptions\InvalidCredentialsException;
use App\Exceptions\InvalidVideoPlaybackSessionException;
use App\Exceptions\LessonNotAccessibleException;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Guarantee JSON rendering for every API request.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
        ]);

        // Attach the "api" rate limiter to the whole api group. Laravel 11 only
        // throttles api routes when this is called; without it the limiter
        // defined in AppServiceProvider never runs. The login route tightens
        // this further with "throttle:login".
        $middleware->throttleApi();

        // Spatie Laravel Permission authorization middleware aliases.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Render all errors as JSON for API requests.
        $exceptions->shouldRenderJsonWhen(function (Request $request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                ], 403);
            }
        });

        // Spatie's role/permission middleware throws its own exception, which
        // extends HttpException rather than AuthorizationException, so it fell
        // through to the generic renderer and echoed "User does not have the
        // right roles." Normalizing it keeps every 403 identical to a client
        // and avoids disclosing that a role model exists.
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                ], 403);
            }
        });

        $exceptions->render(function (InvalidCredentialsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 401);
            }
        });

        $exceptions->render(function (AccountDisabledException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
        });

        $exceptions->render(function (DuplicateEnrollmentException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        });

        $exceptions->render(function (CourseNotPublishedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (LessonNotAccessibleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
        });

        $exceptions->render(function (ExamNotPublishedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ExamNotAccessibleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
        });

        $exceptions->render(function (AttemptLimitReachedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ExamWindowNotOpenException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ExamWindowClosedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (InvalidAttemptStateException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ExamNotReadyToPublishException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ExamStructureLockedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (InvalidCompetitionStateException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (CompetitionNotAccessibleException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
        });

        $exceptions->render(function (CompetitionCapacityFullException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        });

        $exceptions->render(function (DuplicateCompetitionParticipationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        });

        $exceptions->render(function (ResourceDeletionBlockedException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        });

        $exceptions->render(function (InvalidVideoPlaybackSessionException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $previous = $e->getPrevious();
                $message = 'Resource not found.';

                if (! ($previous instanceof ModelNotFoundException) && ! str_contains($e->getMessage(), 'No query results for model')) {
                    $rawMsg = $e->getMessage();
                    if ($rawMsg && ! str_starts_with($rawMsg, 'The route') && ! str_contains($rawMsg, 'App\\')) {
                        $message = $rawMsg;
                    }
                }

                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 404);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Request failed.',
                ], $e->getStatusCode(), $e->getHeaders());
            }
        });

        $exceptions->render(function (\Illuminate\Database\QueryException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $raw = $e->getMessage();
                $message = 'A database error occurred while processing your request.';

                if (str_contains($raw, 'NOT NULL constraint failed') || str_contains($raw, 'cannot be null')) {
                    if (preg_match('/NOT NULL constraint failed:\s*([\w\.]+)/i', $raw, $m) || preg_match('/Column \'(\w+)\' cannot be null/i', $raw, $m)) {
                        $field = str_replace('_', ' ', last(explode('.', $m[1])));
                        $message = 'The field "'.ucfirst($field).'" is required and cannot be empty.';
                    } else {
                        $message = 'Required information is missing or empty.';
                    }

                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 422);
                }

                if (str_contains($raw, 'UNIQUE constraint failed') || str_contains($raw, 'Duplicate entry') || $e->getCode() === '23000' || $e->getCode() === 23000) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A conflicting record with this information already exists.',
                    ], 409);
                }

                if (str_contains($raw, 'FOREIGN KEY constraint failed') || str_contains($raw, 'foreign key constraint fails')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This operation cannot be completed because the item is linked to other records.',
                    ], 409);
                }

                return response()->json([
                    'success' => false,
                    'message' => config('app.debug') ? $e->getMessage() : $message,
                ], 500);
            }
        });

        // Suppress internal exception detail outside of local/debug environments.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && ! config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred.',
                ], 500);
            }
        });
    })->create();
