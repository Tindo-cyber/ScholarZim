<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\Taxonomy\FieldTaxonomy;
use App\Support\EducationLevel;

/**
 * Whether the applicant and the listing are talking about the same subject.
 *
 * Everything interesting happens in FieldTaxonomy; this decides what each answer
 * is worth. The change from v1 is that "Computer Science", "CS", "Computing" and
 * "Computer Science & IT" now reach the same canonical concept, so a student is
 * no longer scored on whether they picked the same words as the provider.
 */
final class FieldMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $credit = config('scholarfit.credit');
        $profileField = $profile->field_of_study;
        $targetField = $opportunity->target_field;

        // Field of study is not a meaningful concept below tertiary level - a
        // Primary or O/A-Level applicant has no "field" to be missing, so a
        // blank value here is not an unfilled field, it is the correct answer.
        // Scoring it as a zero would be exactly the university-shaped penalty
        // this check exists to avoid: the same profile scores zero on this
        // dimension against every single listing, purely for being at a level
        // this dimension does not apply to.
        //
        // Gated on a *known* level, not merely "not known to use it" - a
        // profile with no education_level at all is genuinely missing
        // information, which is a different thing from correctly having
        // nothing to report, and must still score the ordinary "no field" zero.
        $knownLevel = EducationLevel::canonical($profile->education_level) !== null;

        if (blank($profileField) && $knownLevel && ! EducationLevel::usesFieldOfStudy($profile->education_level)) {
            return DimensionResult::make(
                'field',
                'Field',
                (float) $credit['neutral'],
                $weight,
                'Field of study does not apply at your education level'
            );
        }

        if (blank($profileField)) {
            return DimensionResult::make(
                'field',
                'Field',
                0.0,
                $weight,
                'No field of study on your profile',
                'Add your field of study to your profile',
                DimensionResult::TARGET_PROFILE,
                'field_of_study'
            );
        }

        // Open to any subject. Worth a half mark: the applicant is not excluded,
        // but nothing about their subject has been matched either.
        if (blank($targetField)) {
            return DimensionResult::make(
                'field',
                'Field',
                (float) $credit['neutral'],
                $weight,
                'This listing is open to any field of study'
            );
        }

        if (FieldTaxonomy::sameCategory($profileField, $targetField)) {
            return DimensionResult::make(
                'field',
                'Field',
                1.0,
                $weight,
                'Excellent match: ' . FieldTaxonomy::label($targetField)
            );
        }

        if (FieldTaxonomy::related($profileField, $targetField)) {
            return DimensionResult::make(
                'field',
                'Field',
                (float) $credit['related'],
                $weight,
                'Related field: ' . FieldTaxonomy::label($profileField)
                    . ' against ' . FieldTaxonomy::label($targetField)
            );
        }

        return DimensionResult::make(
            'field',
            'Field',
            0.0,
            $weight,
            'Targets ' . FieldTaxonomy::label($targetField)
                . '; your profile shows ' . FieldTaxonomy::label($profileField),
            'Targets ' . $targetField . ' - your profile shows ' . $profileField,
            DimensionResult::TARGET_PROFILE,
            'field_of_study'
        );
    }
}
