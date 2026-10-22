<?php

namespace App\Support;

use App\Models\ApplicantProfile;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * What a profile detail must look like, written once.
 *
 * The profile form and the apply wizard's "add what is missing" step both save details to the same profile record,
 * so they validate them with the same rules. Before this the rules lived inside ProfileController::update(), and a
 * second form would have meant a second, drifting copy.
 *
 * base() is what the profile form accepts (every field optional except gender); forMissing() is the same rule made
 * required, for the wizard, which only ever asks for a field the profile does not have yet.
 */
final class ProfileDetailRules
{
    /** The simple fields the wizard can collect in place; anything else is edited on the profile page. */
    public const INLINE = [
        'institution_name', 'current_programme_id', 'province', 'locality', 'settlement_type',
        'date_of_birth', 'gender', 'biography',
        'guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed',
    ];

    private function __construct()
    {
    }

    /** @return array<string, array<int, mixed>> */
    public static function base(): array
    {
        return [
            'institution_name' => ['nullable', 'string', 'max:255'],
            // Free text everywhere: the platform does not require an institution to exist in a fixed list
            // (FormOptions::INSTITUTIONS is suggestions only), and a Grade 7 pupil's primary school is exactly as valid
            // an answer here as a university's full name.
            'field_of_study' => ['nullable', 'string', 'max:255'],
            // The field of study as a person gives it: a programme from the catalogue. field_of_study above is what is written from it.
            'current_programme_id' => ['nullable', 'integer'],
            'province' => ['nullable', Rule::in(FormOptions::ZIMBABWE_PROVINCES)],
            // A specific place (e.g. "Gweru"), not a fixed list - Zimbabwe has far more towns and growth points than
            // any dropdown would sensibly enumerate, and this is the applicant's own claim about where they live.
            'locality' => ['nullable', 'string', 'max:100'],
            'settlement_type' => ['nullable', Rule::in(SettlementType::ALL)],
            // Exactly Y-m-d, as the date picker sends it: `date` alone also reads "17-05-2000x", "tomorrow" and the like.
            'date_of_birth' => ['bail', 'nullable', 'string', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
            'guardian_name' => ['nullable', 'string', 'max:255', 'regex:' . FormOptions::NAME_PATTERN],
            'guardian_phone' => ['nullable', 'string', 'regex:' . FormOptions::PHONE_PATTERN],
            'guardian_relationship' => ['nullable', 'string', 'max:100'],
            'guardian_confirmed' => ['nullable', 'boolean'],
            'biography' => ['nullable', 'string', 'max:5000'],
            'gender' => ['required', Rule::in(Gender::ALL)],
        ];
    }

    /**
     * The same rule for a field the applicant has been asked to supply: it must now be present.
     *
     * @return array<int, mixed>
     */
    public static function forMissing(string $field): array
    {
        $rules = self::base()[$field] ?? ['required'];

        // A guardian's confirmation is a tick that has to be given, not merely a boolean that may be absent.
        if ($field === 'guardian_confirmed') {
            return ['accepted'];
        }

        return array_values(array_merge(['required'], array_filter($rules, static fn ($rule) => $rule !== 'nullable' && $rule !== 'required')));
    }

    /**
     * Rules that need two fields together, which a per-field list cannot say: a town that is not in the province
     * stated, and a date of birth that does not fit the education level. Fields missing from $data fall back to what
     * the profile already holds, so a wizard that supplies only a date of birth is still judged against the level.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>  field => message
     */
    public static function crossChecks(array $data, ?ApplicantProfile $current = null): array
    {
        $problems = [];
        $held = static fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $current?->{$key};

        $reason = ZimbabweLocalities::mismatchReason($held('locality'), $held('province'));

        if ($reason !== null && (array_key_exists('locality', $data) || array_key_exists('province', $data))) {
            // Said against the field the person just supplied: if they gave only a province, the town it contradicts is
            // already on their profile and is not on the page, so the error belongs beside the province they can change.
            $problems[array_key_exists('locality', $data) ? 'locality' : 'province'] = $reason;
        }

        $dateOfBirth = $data['date_of_birth'] ?? null;

        if (filled($dateOfBirth)) {
            $age = Carbon::parse($dateOfBirth)->age;

            if ($age < EducationLevel::MINIMUM_APPLICANT_AGE) {
                $problems['date_of_birth'] = 'You must be at least ' . EducationLevel::MINIMUM_APPLICANT_AGE . ' years old to use this platform.';
            } elseif (filled($level = $held('education_level'))) {
                $reason = EducationLevel::ageConsistencyReason($level, $age);

                if ($reason !== null) {
                    $problems['date_of_birth'] = $reason;
                }
            }
        }

        return $problems;
    }
}
