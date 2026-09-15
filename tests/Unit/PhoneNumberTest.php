<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The canonical phone normalization rule.
 *
 * One rule, one storage format (E.164 with a leading '+'), and no silent
 * guessing: anything that cannot be interpreted is rejected rather than
 * reshaped into something plausible.
 */
class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function egyptianNormalizationProvider(): array
    {
        return [
            'local trunk format' => ['01012345678', '+201012345678'],
            'E.164 with plus' => ['+201012345678', '+201012345678'],
            'country code without plus' => ['201012345678', '+201012345678'],
            'international 00 prefix' => ['00201012345678', '+201012345678'],
            'with spaces' => ['+20 101 234 5678', '+201012345678'],
            'with dashes and parens' => ['+20 (101) 234-5678', '+201012345678'],
            'national number without trunk zero' => ['1012345678', '+201012345678'],
            'Vodafone prefix 011' => ['01112345678', '+201112345678'],
            'Etisalat prefix 012' => ['01212345678', '+201212345678'],
            'WE prefix 015' => ['01512345678', '+201512345678'],
            'leading and trailing whitespace' => ["  01012345678  \n", '+201012345678'],
        ];
    }

    #[DataProvider('egyptianNormalizationProvider')]
    public function test_normalizes_supported_egyptian_formats(mixed $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    /**
     * The critical regression: a number that already carries +20 must not
     * become +2020...
     */
    public function test_plus_twenty_is_not_double_prefixed(): void
    {
        $once = PhoneNumber::normalize('+201012345678');

        $this->assertSame('+201012345678', $once);

        // Normalizing an already-normalized value is stable (idempotent), so
        // re-saving a record can never corrupt the stored number.
        $this->assertSame($once, PhoneNumber::normalize($once));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPhoneProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'letters' => ['abcdef'],
            'too short' => ['123'],
            'missing a digit' => ['0101234567'],
            'too long' => ['010123456789'],
            'landline cairo' => ['0221234567'],
            'invalid mobile prefix 013' => ['01312345678'],
            'invalid mobile prefix 014' => ['01412345678'],
            'invalid mobile prefix 019' => ['01912345678'],
            'double dialling code' => ['+202012345678'],
            'symbols only' => ['+()-'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function test_invalid_numbers_are_rejected_not_guessed(mixed $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
        $this->assertNull(PhoneNumber::toWhatsApp($input));
        $this->assertFalse(PhoneNumber::isValid($input));
    }

    public function test_whatsapp_form_strips_the_leading_plus(): void
    {
        $this->assertSame('201012345678', PhoneNumber::toWhatsApp('01012345678'));
        $this->assertSame('201012345678', PhoneNumber::toWhatsApp('+201012345678'));
    }

    public function test_is_valid_accepts_supported_formats(): void
    {
        $this->assertTrue(PhoneNumber::isValid('01012345678'));
        $this->assertTrue(PhoneNumber::isValid('+201012345678'));
        $this->assertFalse(PhoneNumber::isValid('0221234567'));
    }

    #[Test]
    public function test_unsupported_country_is_rejected_rather_than_assumed_egyptian(): void
    {
        // No French configuration exists yet, so a French number must not be
        // silently coerced into an Egyptian one.
        $this->assertNull(PhoneNumber::normalize('0612345678', 'FR'));
    }
}
