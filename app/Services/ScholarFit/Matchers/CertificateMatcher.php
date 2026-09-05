<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;

/**
 * Whether the applicant is ready to apply, document-wise.
 *
 * This is the smallest dimension and the only one about readiness rather than
 * fit. Where a listing demands the document the gate has already turned away
 * anyone without one, so reaching here with the requirement set means it is
 * satisfied; where no listing demands it, holding one still counts for a
 * little, because it is the document most Zimbabwean providers ask for at
 * interview.
 *
 * "The document" is level-dependent, via
 * `ApplicantProfile::hasRequiredAcademicEvidence()`: a results certificate for
 * an O/A-Level applicant, a transcript for anyone at Certificate level or
 * above. There is no such thing as a Masters applicant's "results
 * certificate", so scoring this dimension against the wrong document would
 * have quietly zeroed it for every tertiary and postgraduate applicant.
 *
 * The wording differs between the "required" and "not required" cases even
 * though the marks do not, which is the point of routing explanations through
 * the same object as the score: "required, and you have it" and "not
 * required, but you have it" are the same five points and genuinely different
 * sentences.
 */
final class CertificateMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $held = $profile->hasRequiredAcademicEvidence();
        $required = (bool) $opportunity->requires_results_certificate;
        $documentLabel = \App\Support\EducationLevel::usesSchoolResults($profile->education_level)
            ? 'Results certificate'
            : 'Transcript';

        if ($held) {
            return DimensionResult::make(
                'certificate',
                'Certificate',
                1.0,
                $weight,
                $required
                    ? $documentLabel . ' uploaded, as this listing requires'
                    : $documentLabel . ' uploaded'
            );
        }

        return DimensionResult::make(
            'certificate',
            'Certificate',
            0.0,
            $weight,
            'No ' . strtolower($documentLabel) . ' uploaded',
            'Upload your ' . strtolower($documentLabel) . ' before applying',
            DimensionResult::TARGET_DOCUMENTS,
            'documents'
        );
    }
}
