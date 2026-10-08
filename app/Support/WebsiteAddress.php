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
            if (! preg_match('~^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+(:\d+)?(/.*)?$~i', $trimmed)) {
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

    /** Validation rules for a website field: optional, an http(s) address. */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:255', 'url:http,https'];
    }
}
