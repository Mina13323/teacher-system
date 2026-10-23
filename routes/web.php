<?php

use App\Http\Controllers\SpaController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Web SPA Catch-All Route
|--------------------------------------------------------------------------
|
| Serves the compiled Vue 3 PWA entrypoint (index.html) for all non-API web
| routes. API routes under /api/v1/* are registered in routes/api.php and
| return canonical JSON envelopes.
|
| The entry page is a static file and reads no session, flash data or CSRF
| token (the SPA authenticates the API with a bearer token), so the session
| middleware is skipped. With SESSION_DRIVER=database it would otherwise open
| a MySQL connection on every page load just to write an unused session row.
|
*/
Route::get('/{any}', SpaController::class)
    ->where('any', '^(?!api).*$')
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
