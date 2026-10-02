<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ProfileController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restrict temporary-credential accounts to identity and password recovery. */
class ForcePasswordChange
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $routeAction = $request->route()?->getActionName();
        $allowedActions = [
            AuthController::class.'@me',
            AuthController::class.'@logout',
            ProfileController::class.'@show',
            ProfileController::class.'@changePassword',
        ];

        if (in_array($routeAction, $allowedActions, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Change your temporary password before continuing.',
            'code' => 'password_change_required',
        ], 403);
    }
}
