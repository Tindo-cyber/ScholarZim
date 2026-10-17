<?php

namespace App\Support;

/**
 * Turn what a person typed into a web address, or say it is not one.
 *
 * People write "kariba-trust.org", "www.kariba-trust.org" and "https://..."
 * interchangeably, and a form that insists on the scheme turns a harmless habit
 * into a validation error. The scheme is added when it is missing; nothing else
 * is guessed. Only http and https are accepted, so a "website" cannot be a
 * javascript: or mailto: link that later gets rendered as one.
 */
final class WebsiteAddress
{
    private function __construct()
    {
    }

    /**
     * The address with a scheme, or null when blank. A value that cannot be an
     * address is returned unchanged, so the validator rejects it with the
     * provider's own text in the message rather than a rewritten one.
     */
    public static function normalise(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('~^[a-z][a-z0-9+.\-]*://~i', $trimmed)) {
            // Only a plausible host gets a scheme; "not a website" must still fail.
            if (! preg_match('~^[\p{L}\p{N}]([\p{L}\p{N}\-]*[\p{L}\p{N}])?(\.[\p{L}\p{N}]([\p{L}\p{N}\-]*[\p{L}\p{N}])?)+(:\d+)?(/.*)?$~iu', $trimmed)) {
                return $trimmed;
            }

            $trimmed = 'https://' . $trimmed;
        }

        return rtrim($trimmed, '/') === $trimmed ? $trimmed : rtrim($trimmed, '/');
    }

    /** The host without a leading www., lower-cased, or null. */
    public static function host(?string $address): ?string
    {
        if (! filled($address)) {
            return null;
        }

        $host = parse_url(trim($address), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * The address as a link that is safe to show to the public, or null when there is nothing safe to show.
     *
     * Checked again at display time because the stored value may predate the validation rules or have been
     * edited in the database. Only http(s) with a real host passes, and a login embedded in the address
     * ("https://trusted.org@evil.example") is refused, since it disguises where the link goes. Unicode host
     * names (internationalised domains) are allowed, which PHP's FILTER_VALIDATE_URL would wrongly reject.
     */
    public static function publicLink(?string $address): ?string
    {
        $address = self::normalise($address);

        if ($address === null || preg_match('/[\s\x00-\x1F\x7F<>"\\\\]/u', $address)) {
            return null;
        }

        $parts = parse_url($address);

        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! filled($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || ! str_contains($parts['host'], '.')) {
            return null;
        }

        return $address;
    }

    /** Validation rules for a website field: optional, an http(s) address. */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:255', 'url:http,https'];
    }
}
