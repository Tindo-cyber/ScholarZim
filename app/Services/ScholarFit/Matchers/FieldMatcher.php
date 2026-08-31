<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\Taxonomy\FieldTaxonomy;

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

        if (blank($profileField)) {
            return DimensionResult::make(
                'field',
                'Field',
                0.0,
                $weight,
                'No field of study on your profile',
                'Add your field of study to your profile',
                EligibilityEvaluator::PROFILE_FIELD,
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
            EligibilityEvaluator::PROFILE_FIELD,
            'field_of_study'
        );
    }
}
