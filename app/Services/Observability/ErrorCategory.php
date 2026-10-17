<?php

namespace App\Services\Observability;

use App\Database\ConnectionRefusal;
use App\Exceptions\InvalidAttemptStateException;
use Illuminate\Database\QueryException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A short, stable label for why an API request failed, added to the request
 * log line so failures can be counted by kind. Never contains user data.
 */
final class ErrorCategory
{
    public const DB_CONNECT_FINAL = 'DB_CONNECT_FINAL';

    public const DB_QUERY_FAILURE = 'DB_QUERY_FAILURE';

    public const AUTH_FAILURE = 'AUTH_FAILURE';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const EXAM_STATE_REJECTED = 'EXAM_STATE_REJECTED';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public static function forResponse(Response $response): ?string
    {
        $exception = property_exists($response, 'exception') ? $response->exception : null;

        return self::classify($response->getStatusCode(), $exception instanceof Throwable ? $exception : null);
    }

    public static function classify(int $status, ?Throwable $exception = null): ?string
    {
        if ($exception !== null && ConnectionRefusal::matches($exception)) {
            return self::DB_CONNECT_FINAL;
        }

        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            return self::DB_QUERY_FAILURE;
        }

        if ($exception instanceof InvalidAttemptStateException) {
            return self::EXAM_STATE_REJECTED;
        }

        return match (true) {
            $status === 401 => self::AUTH_FAILURE,
            $status === 429 => self::RATE_LIMITED,
            $status >= 500 => self::SERVER_ERROR,
            default => null,
        };
    }
}
