<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web SPA Catch-All Route
|--------------------------------------------------------------------------
|
| Serves the compiled Vue 3 PWA entrypoint (index.html) for all non-API web
| routes. API routes under /api/v1/* are registered in routes/api.php and
| return canonical JSON envelopes.
|
*/
Route::get('/{any}', SpaController::class)->where('any', '^(?!api).*$');
