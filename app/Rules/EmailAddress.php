<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The shape of an email address a person can really be reached on.
 *
 * Laravel's `email` rule follows the RFC, which allows addresses nobody means to give: "a@b" (no top-level domain), "a@[127.0.0.1]"
 * (an IP literal) and "\"a b\"@c.com" (a quoted local part). This rule sits beside `email`, not instead of it, and adds what an
 * ordinary mailbox needs: one @, a local part with no quotes, brackets or spaces and no leading, trailing or doubled dot, and a
 * domain of dot-separated labels ending in a top-level domain of at least two letters (or an IDN's xn-- form).
 */
class EmailAddress implements ValidationRule
{
    /** The rules to use wherever an address is asked for: bail on the first failure, required, a string, its length, Laravel's own check, and this shape. */
    public static function rules(bool $required = true): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'string', 'max:254', 'email', new self()];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || substr_count($value, '@') !== 1) {
            $fail('Enter a valid email address.');

            return;
        }

        [$local, $domain] = explode('@', $value);

        $localOk = $local !== '' && strlen($local) <= 64
            && preg_match('/^[^\s"(),:;<>@\[\]\x5c]+$/u', $local) === 1
            && ! str_starts_with($local, '.') && ! str_ends_with($local, '.') && ! str_contains($local, '..');

        $domainOk = preg_match('/^(?:[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?\.)+(?:[\p{L}]{2,63}|xn--[a-z0-9-]{2,59})$/iu', $domain) === 1;

        if (! $localOk || ! $domainOk) {
            $fail('Enter a valid email address.');
        }
    }
}
