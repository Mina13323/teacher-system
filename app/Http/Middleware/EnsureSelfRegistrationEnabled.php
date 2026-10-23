<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Student self-registration (the teacher's registration link and the public
 * page it opens) is switched off unless STUDENT_SELF_REGISTRATION=true.
 */
class EnsureSelfRegistrationEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('app.student_self_registration', false), 404, 'Student self-registration is turned off.');

        return $next($request);
    }
}
