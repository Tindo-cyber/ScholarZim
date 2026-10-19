<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;

/**
 * What a provider is shown about an applicant: the recorded subject results and the profile fields,
 * taken when the application is sent and kept with it.
 */
class ApplicationSnapshot
{
    /** @return array{eligibility?: array<string, mixed>, profile: array<string, mixed>, minor: bool, guardian: ?array<string, mixed>, results: array<int, array<string, mixed>>} */
    public static function build(ApplicantProfile $profile, ?EligibilityResult $fit = null): array
    {
        $minor = $profile->isMinor();

        return ($fit ? ['eligibility' => self::eligibility($fit)] : []) + [
            'profile' => [
                'education_level' => $profile->education_level,
                'institution_name' => $profile->institution_name,
                'field_of_study' => $profile->field_of_study,
                'uses_field_of_study' => EducationLevel::usesFieldOfStudy($profile->education_level),
                'province' => $profile->province,
                'locality' => $profile->locality,
                'age' => $profile->age(),
                'gender' => $profile->gender,
                'biography' => $profile->biography,
            ],
            'minor' => $minor,
            'guardian' => $minor ? [
                'name' => $profile->guardian_name,
                'phone' => $profile->guardian_phone,
                'relationship' => $profile->guardian_relationship,
                'confirmed_at' => $profile->guardian_confirmed_at?->toIso8601String(),
            ] : null,
            'results' => $profile->academicResults()->with(['qualification', 'subject'])->orderBy('id')->get()
                ->map(fn ($r) => [
                    'qualification' => $r->qualificationName(),
                    'subject' => $r->subjectName(),
                    'result' => $r->result,
                    'points' => $r->points(),
                ])->values()->all(),
        ];
    }

    /**
     * Whether two saved verdicts say the same thing.
     *
     * Compared by content, not by the order of an object's keys: MySQL's JSON type does not keep that order, so a
     * verdict read back from the database can list the same keys differently from one just worked out, and a plain
     * strict comparison then calls an unchanged verdict changed.
     */
    public static function sameVerdict(array $a, array $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The verdict and its reasons as plain data, so they can be kept and shown later exactly as they were.
     * Verdict is one of eligible, not_eligible, needs_information, no_requirements ("insufficient" appears only in snapshots saved before that state was retired).
     *
     * @return array{verdict: string, rules: array<int, array{status: string, message: string}>, notes: array<int, string>}
     */
    public static function eligibility(EligibilityResult $fit): array
    {
        $rules = RequirementOutcome::rules($fit->outcomes);

        return [
            'verdict' => match (true) {
                $rules === [] => 'no_requirements',
                $fit->meetsRequirements() => 'eligible',
                $fit->needsReview() => 'needs_review',
                $fit->needsInformation() => 'needs_information',
                default => 'not_eligible',
            },
            'rules' => array_map(fn ($o) => [
                'status' => $o->needsInformation ? 'pending' : ($o->passed ? 'met' : 'unmet'),
                'message' => $o->message,
            ], $rules),
            'notes' => array_map(fn ($o) => $o->message, $fit->advisoryNotes()),
        ];
    }
}
