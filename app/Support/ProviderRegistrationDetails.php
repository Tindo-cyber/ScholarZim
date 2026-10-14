<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The organisation-registration fields, validated the same way wherever they
 * are entered: at registration, by an organisation answering a request for more
 * information, and by an administrator recording details for an older account.
 *
 * Nothing here is a country's legal rule. Registration numbers take many shapes
 * ("2019/123456/08", "NPO 123-456", "CH-020.3.912.345-6", "12345678"), so the
 * pattern only insists on letters, digits and the separators those use. An
 * organisation without such a number - a body created by statute, say - says
 * why instead, and the administrator weighs that against the document.
 */
final class ProviderRegistrationDetails
{
    /** Starts with a letter or digit; then letters, digits, spaces and . / - ( ) # : & , */
    public const NUMBER_PATTERN = '/^[\p{L}\p{N}][\p{L}\p{N} .\/\-()#:&,]*$/u';

    private function __construct()
    {
    }

    /**
     * @param  bool  $requireNumberOrNote  false only for an administrator recording what is known about an
     *                                     older account, where neither may have been collected
     */
    public static function rules(bool $requireNumberOrNote = true): array
    {
        $number = ['nullable', 'string', 'min:2', 'max:100', 'regex:' . self::NUMBER_PATTERN];
        $note = ['nullable', 'string', 'max:500'];

        if ($requireNumberOrNote) {
            $number[] = 'required_without:registration_number_note';
            $note[] = 'required_without:registration_number';
        }

        return [
            // Checked against the list here: a value the browser sends is not trusted to be a country.
            'registration_country' => ['required', 'string', Rule::in(Countries::codes())],
            'registration_number' => $number,
            'registration_number_note' => $note,
        ];
    }

    public static function messages(): array
    {
        return [
            'registration_country.required' => 'Choose the country your organisation is registered in.',
            'registration_country.in' => 'Choose a country from the list.',
            'registration_number.regex' => 'Use only the letters, digits and separators (. / - ( ) # : & ,) your registration number contains.',
            'registration_number.required_without' => 'Give your official registration number, or explain why your organisation does not have one.',
            'registration_number_note.required_without' => 'Explain why your organisation has no registration number, or give the number above.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'registration_country' => 'country of registration',
            'registration_number' => 'registration number',
            'registration_number_note' => 'explanation',
        ];
    }

    /**
     * Tidies the request before validation: a code in either case, a number
     * without stray whitespace, and blanks as null. Only the fields the request
     * actually carries are returned, so a form that leaves one out does not
     * blank what is on record.
     */
    public static function prepare(array $input): array
    {
        // Anything but a plain value is passed through for the validator to refuse.
        $tidy = static fn ($value) => ! is_scalar($value) && $value !== null
            ? $value
            : (($value = trim(preg_replace('/\s+/u', ' ', (string) $value))) === '' ? null : $value);
        $prepared = [];

        foreach (['registration_country', 'registration_number', 'registration_number_note'] as $field) {
            if (array_key_exists($field, $input)) {
                $prepared[$field] = $tidy($input[$field]);
            }
        }

        if (is_string($prepared['registration_country'] ?? null)) {
            $prepared['registration_country'] = strtoupper($prepared['registration_country']);
        }

        return $prepared;
    }

    /** The validated values, as the profile stores them - only those that were submitted. */
    public static function attributesFrom(array $data): array
    {
        return array_intersect_key($data, array_flip(['registration_country', 'registration_number', 'registration_number_note']));
    }
}
