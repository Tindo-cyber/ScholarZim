<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Support\EducationLevel;

/**
 * What a provider is shown about an applicant: the recorded subject results and the profile fields,
 * taken when the application is sent and kept with it.
 */
class ApplicationSnapshot
{
    /** @return array{profile: array<string, mixed>, minor: bool, guardian: ?array<string, mixed>, results: array<int, array<string, mixed>>} */
    public static function build(ApplicantProfile $profile): array
    {
        $minor = $profile->isMinor();

        return [
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
}
