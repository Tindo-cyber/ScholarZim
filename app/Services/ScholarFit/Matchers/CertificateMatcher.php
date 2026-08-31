<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\EligibilityEvaluator;

/**
 * Whether the applicant is ready to apply, document-wise.
 *
 * This is the smallest dimension and the only one about readiness rather than
 * fit. Where a listing demands a results certificate the gate has already turned
 * away anyone without one, so reaching here with the requirement set means it is
 * satisfied; where no listing demands it, holding one still counts for a little,
 * because it is the document most Zimbabwean providers ask for at interview.
 *
 * The wording differs between those two cases even though the marks do not,
 * which is the point of routing explanations through the same object as the
 * score: "required, and you have it" and "not required, but you have it" are the
 * same five points and genuinely different sentences.
 */
final class CertificateMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $held = $profile->hasResultsCertificate();
        $required = (bool) $opportunity->requires_results_certificate;

        if ($held) {
            return DimensionResult::make(
                'certificate',
                'Certificate',
                1.0,
                $weight,
                $required
                    ? 'Results certificate uploaded, as this listing requires'
                    : 'Results certificate uploaded'
            );
        }

        return DimensionResult::make(
            'certificate',
            'Certificate',
            0.0,
            $weight,
            'No results certificate uploaded',
            'Upload your results certificate before applying',
            EligibilityEvaluator::PROFILE_DOCUMENTS,
            'documents'
        );
    }
}
