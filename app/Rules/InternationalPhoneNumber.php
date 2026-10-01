<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

/**
 * A phone number valid under libphonenumber's real international numbering
 * plans - mobile or landline, any country, with an optional extension - for
 * a provider's organisation contact number.
 *
 * This is deliberately not FormOptions::PHONE_PATTERN, and not a second,
 * hand-written regex either: the brief that introduced this rule is explicit
 * that a provider's number must accept Zimbabwean and international mobile
 * and landline numbers, with an optional extension, without replacing one
 * simplistic country-specific pattern with an equally simplistic global one.
 * libphonenumber is Google's own numbering-plan data, kept current as
 * countries' plans change, which a hand-rolled pattern cannot be.
 *
 * The applicant's own phone field is untouched by this - see
 * FormOptions::PHONE_PATTERN's docblock - because the brief that introduced
 * this rule is just as explicit that the two serve different purposes and
 * must not be unified into one.
 */
class InternationalPhoneNumber implements ValidationRule
{
    /**
     * Only the digits-and-punctuation shape is parsed here as a last
     * resort; the region a number is read against when it states no
     * country code of its own. ScholarZim is a Zimbabwean platform, so a
     * bare local number ("0242771234") is read as Zimbabwean rather than
     * rejected for lacking a country code it was never asked to include.
     * A number that does state its own country code (+27, +44, +1, ...) is
     * read as whatever country it names, regardless of this default.
     */
    private const DEFAULT_REGION = 'ZW';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute must be a valid phone number.');

            return;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($value, self::DEFAULT_REGION);
        } catch (NumberParseException) {
            $fail('The :attribute must be a valid phone number.');

            return;
        }

        if (! $util->isValidNumber($parsed)) {
            $fail('The :attribute must be a valid phone number.');
        }
    }
}
