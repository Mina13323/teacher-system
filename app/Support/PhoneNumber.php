<?php

namespace App\Support;

/**
 * The single canonical phone normalization rule.
 *
 * This is the ONLY place phone numbers are normalized. Controllers, form
 * requests, resources and the WhatsApp deep-link all go through here, so the
 * database, the API and the UI can never disagree about a number's shape.
 *
 * Storage format is E.164 with a leading '+' (e.g. +201012345678). One stored
 * representation per number, never several.
 *
 * Country handling is table-driven rather than Egypt-specific code, so adding a
 * country means adding one row rather than rewriting the logic.
 *
 * Invalid or unrecognized input returns null. It is never silently guessed into
 * a plausible-looking number.
 */
final class PhoneNumber
{
    public const DEFAULT_COUNTRY = 'EG';

    /**
     * Supported countries.
     *
     * `dial`    international dialling code, no leading '+' or zeros
     * `national` pattern the significant (national) number must match
     *
     * Egypt is configured for MOBILE numbers only, because the consumer of this
     * helper is a WhatsApp deep link and WhatsApp has no landline accounts.
     *
     * @var array<string, array{dial: string, national: string}>
     */
    private const COUNTRIES = [
        'EG' => ['dial' => '20', 'national' => '/^1[0125][0-9]{8}$/'],
    ];

    /**
     * Normalize a raw phone value to E.164, or null when it is not a valid
     * number for the given country.
     *
     * Accepted Egyptian inputs all normalize to +201012345678:
     *   01012345678
     *   +201012345678
     *   201012345678
     *   00201012345678
     *   +20 101 234 5678
     *
     * A number that already carries the country code is never double-prefixed.
     */
    public static function normalize(?string $raw, string $country = self::DEFAULT_COUNTRY): ?string
    {
        $config = self::COUNTRIES[strtoupper($country)] ?? null;

        if ($config === null || $raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');

        // Keep digits only; separators and spaces are formatting, not data.
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00 is the international access prefix used across much of the world.
        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $national = self::significantNumber($digits, $config['dial']);

        if ($national === null || ! preg_match($config['national'], $national)) {
            return null;
        }

        return '+'.$config['dial'].$national;
    }

    /**
     * The digits to place in a wa.me URL: E.164 without the leading '+'.
     *
     * Returns null when the number is absent or invalid, so callers never build
     * a link to a guessed destination.
     */
    public static function toWhatsApp(?string $raw, string $country = self::DEFAULT_COUNTRY): ?string
    {
        $normalized = self::normalize($raw, $country);

        return $normalized === null ? null : substr($normalized, 1);
    }

    /**
     * Whether the value is a valid, normalized-able number.
     */
    public static function isValid(?string $raw, string $country = self::DEFAULT_COUNTRY): bool
    {
        return self::normalize($raw, $country) !== null;
    }

    /**
     * Reduce digits to the significant national number by removing an existing
     * dialling code or a trunk zero. Returns null when the digits cannot be
     * interpreted for this country.
     */
    private static function significantNumber(string $digits, string $dial): ?string
    {
        // Already carries the country code: strip it and keep the remainder.
        if (str_starts_with($digits, $dial)) {
            $remainder = substr($digits, strlen($dial));

            if ($remainder !== '' && $remainder[0] !== '0') {
                return $remainder;
            }
        }

        // Local format with a trunk prefix: 01012345678 -> 1012345678.
        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
