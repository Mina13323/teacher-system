<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpaController extends Controller
{
    /**
     * Handle the incoming request and serve the Vue 3 SPA entrypoint.
     */
    public function __invoke(): BinaryFileResponse|JsonResponse
    {
        $path = public_path('index.html');
        if (file_exists($path)) {
            return response()->file($path);
        }

        return response()->json(['message' => 'Single Page Application entrypoint not built.'], 404);
    }
}
