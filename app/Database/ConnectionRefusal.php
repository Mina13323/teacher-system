<?php

namespace App\Database;

use Throwable;

/**
 * Recognizes a MySQL server refusing to open a NEW connection.
 *
 * These errors happen while PDO is still connecting, before any statement is
 * sent, so nothing has been read or written yet:
 *
 *  - [2002] Operation not permitted: the hosting platform refused the socket
 *    connect. Hostinger documents this as its per-account limit on new MySQL
 *    connections per second.
 *  - [1040] Too many connections: the server-wide connection limit.
 *  - [1226] ... 'max_user_connections': the per-user connection limit.
 *
 * Other 2002 errors (MySQL down, missing socket) and every error raised by a
 * query on an open connection are deliberately NOT matched.
 */
final class ConnectionRefusal
{
    public static function matches(?Throwable $e): bool
    {
        for ($depth = 0; $e !== null && $depth < 5; $depth++, $e = $e->getPrevious()) {
            if (self::messageMatches($e->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /** The MySQL error number of a refusal, for logs. */
    public static function errorNumber(?Throwable $e): ?int
    {
        for ($depth = 0; $e !== null && $depth < 5; $depth++, $e = $e->getPrevious()) {
            if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\] \[(\d{4})\]/', $e->getMessage(), $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private static function messageMatches(string $message): bool
    {
        if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\] \[2002\] Operation not permitted/', $message)) {
            return true;
        }

        if (preg_match('/SQLSTATE\[[0-9A-Z]{5}\] \[1040\]/', $message)) {
            return true;
        }

        return (bool) preg_match('/SQLSTATE\[[0-9A-Z]{5}\] \[1226\].*max_user_connections/', $message);
    }
}
