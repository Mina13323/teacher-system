<?php

namespace App\Support;

/**
 * A short, human label for a sign-in ("Chrome on Android"), shown when the
 * account is asked to move to another device. Only the browser family and
 * the operating system are kept: no version, no IP address, nothing that
 * identifies the device. It is a display hint, never an identity: the
 * sign-in itself is identified by its random token.
 */
class DeviceLabel
{
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'CrOS' => 'ChromeOS',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function from(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        $browser = self::first(self::BROWSERS, $ua);
        $system = self::first(self::SYSTEMS, $ua);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => $system,
            default => 'Unknown device',
        };
    }

    private static function first(array $needles, string $ua): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($ua, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
