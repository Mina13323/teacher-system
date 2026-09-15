<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a phone number that cannot be normalized to a valid E.164 number for
 * the configured country.
 *
 * The actual normalization lives in App\Support\PhoneNumber and is applied on
 * write by the User model mutator, so this rule only has to answer one
 * question: is this value acceptable?
 *
 * Empty values pass, because phone is optional throughout the schema; use
 * `required` alongside this rule when a number is mandatory.
 */
class ValidPhoneNumber implements ValidationRule
{
    public function __construct(
        private readonly string $country = PhoneNumber::DEFAULT_COUNTRY,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || ! PhoneNumber::isValid($value, $this->country)) {
            $fail('The :attribute must be a valid phone number, including the country code (for example +201012345678).');
        }
    }
}
