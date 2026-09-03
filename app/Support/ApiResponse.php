<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Shared API response helpers.
 *
 * Every phase-1 controller uses this trait so all responses share the same
 * canonical envelope:
 *
 *  success  -> { success, message, data }
 *  error    -> { success, message, errors? }
 */
trait ApiResponse
{
    /**
     * Raw JSON envelope.
     */
    protected function apiResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(
            $payload,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Success response.
     */
    protected function success(mixed $data = null, string $message = 'Operation completed successfully.', int $status = 200): JsonResponse
    {
        return $this->apiResponse([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Generic error response.
     */
    protected function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return $this->apiResponse($payload, $status);
    }
}
