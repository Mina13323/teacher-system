<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

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
        // A paginated resource collection only adds its `links` and `meta`
        // fields when it is rendered as a resource response. Passing it
        // directly to response()->json() silently serializes just the records,
        // leaving clients with no way to navigate beyond the first page.
        // Keep the established `data` array contract and expose paginator
        // metadata beside it, rather than nesting records a second time.
        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            $page = $data->response()->getData(true);

            return $this->apiResponse([
                'success' => true,
                'message' => $message,
                'data' => $page['data'],
                'meta' => $page['meta'],
                'links' => $page['links'],
            ], $status);
        }

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
