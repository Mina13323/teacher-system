<?php

namespace App\Support\Push;

/**
 * SSRF guard for browser-supplied Web Push endpoints. Endpoints must use HTTPS
 * and a configured push-service hostname; redirects and literal IP targets are
 * never accepted. Network egress policy should still be applied at deployment.
 */
final class WebPushEndpoint
{
    public static function isAllowed(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 500) {
            return false;
        }

        $parts = parse_url($endpoint);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        $allowedHosts = config('push.allowed_endpoint_hosts', []);
        if (is_string($allowedHosts)) {
            $allowedHosts = explode(',', $allowedHosts);
        }
        if (! is_array($allowedHosts)) {
            return false;
        }

        foreach ($allowedHosts as $configuredHost) {
            $rule = strtolower(trim((string) $configuredHost));
            if ($rule === '') {
                continue;
            }

            if (str_starts_with($rule, '*.')) {
                $suffix = substr($rule, 2);
                if ($suffix !== '' && str_ends_with($host, '.'.$suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $rule) {
                return true;
            }
        }

        return false;
    }
}
