<?php

namespace App\Services\ScholarFit\Matchers;

use App\Models\ApplicantProfile;
use App\Models\Opportunity;
use App\Services\ScholarFit\DimensionResult;
use App\Services\ScholarFit\Taxonomy\SettlementType;

/**
 * Geographic compatibility, scored as a hierarchy rather than a single guess.
 *
 * ScholarZim is a Zimbabwe-only platform, so country is no longer a tier a
 * profile can fail: every applicant and every listing is implicitly Zimbabwean,
 * and asking a student to confirm that on their profile was asking a question
 * with one possible answer. What remains is genuinely variable - province,
 * locality (a specific place, e.g. Gweru), and settlement type (rural or
 * urban) - and each is only assessed when the listing actually targets it, so
 * a listing that names nothing geographic cannot quietly collect most of the
 * weight the way an earlier version's 53%-for-silence branch did.
 *
 * Locality is deliberately never held against a profile that has not stated
 * one. A student with Province = Midlands and no locality is not penalised
 * against a listing that targets Midlands generally, or Zimbabwe generally -
 * only a listing that names a specific locality asks the question at all, and
 * an unanswered question earns nothing rather than being read as a mismatch.
 *
 * Every tier is additive, and the tiers actually offered (province + locality
 * + settlement type) must sum to 1.0 for a fully-matched, fully-targeted
 * listing to reach full marks - that is asserted in the tests rather than left
 * as an assumption about config.
 */
final class LocationMatcher
{
    public function match(ApplicantProfile $profile, Opportunity $opportunity, int $weight): DimensionResult
    {
        $tiers = config('scholarfit.location');
        $credit = config('scholarfit.credit');

        $earned = 0.0;
        // Only a tier the listing targets *and* the profile can answer counts
        // toward what was "available" to earn. A tier the profile left blank is
        // an unanswered question, not a mismatch, and must not silently reduce
        // the ratio the way it would if it stayed in the denominator with
        // nothing in the numerator - that is what made this the one place a
        // missing field used to look like a wrong answer instead of an unknown.
        $available = 0.0;
        $matched = [];

        [$share, $inPlay] = $this->tier(
            $opportunity->required_province,
            $profile->province,
            (float) $tiers['province'],
            $matched
        );
        $earned += $share;
        $available += $inPlay ? (float) $tiers['province'] : 0.0;

        [$share, $inPlay] = $this->tier(
            $opportunity->target_locality,
            $profile->locality,
            (float) $tiers['locality'],
            $matched
        );
        $earned += $share;
        $available += $inPlay ? (float) $tiers['locality'] : 0.0;

        [$share, $inPlay] = $this->settlementTypeTier($opportunity, $profile, (float) $tiers['settlement_type'], $matched);
        $earned += $share;
        $available += $inPlay ? (float) $tiers['settlement_type'] : 0.0;

        // Nothing the listing targets could be assessed against this profile -
        // either it targets nothing geographic at all, or it targets something
        // the profile has simply not stated yet. Both are "unknown", not "no
        // match", so both earn the same half mark rather than a zero.
        if ($available <= 0.0) {
            return DimensionResult::make(
                'location',
                'Location',
                (float) $credit['neutral'],
                $weight,
                blank($opportunity->required_province) && blank($opportunity->target_locality)
                    && SettlementType::canonical($opportunity->target_settlement_type) === null
                    ? 'This listing does not target a particular location'
                    : 'This listing targets a location your profile has not stated yet'
            );
        }

        // A listing that targets only a province, with nothing narrower named,
        // would otherwise be capped at its province tier alone. Matching
        // everything a listing asked for is a full match by definition.
        $ratio = $earned / $available;

        return DimensionResult::make(
            'location',
            'Location',
            $ratio,
            $weight,
            $matched === []
                ? 'Your location does not match what this listing targets'
                : 'Matches on ' . implode(', ', $matched)
        );
    }

    /**
     * One narrowing tier. Only scored when the listing targets it and the
     * profile can answer.
     *
     * @return array{0: float, 1: bool} the earned share, and whether this tier
     *                                  was in play at all (targeted, and the
     *                                  profile had something to compare)
     */
    private function tier(?string $target, ?string $held, float $share, array &$matched): array
    {
        if (blank($target) || blank($held)) {
            return [0.0, false];
        }

        if (strcasecmp(trim($held), trim($target)) !== 0) {
            return [0.0, true];
        }

        $matched[] = $target;

        return [$share, true];
    }

    /** @return array{0: float, 1: bool} */
    private function settlementTypeTier(
        Opportunity $opportunity,
        ApplicantProfile $profile,
        float $share,
        array &$matched
    ): array {
        $target = SettlementType::canonical($opportunity->target_settlement_type);
        $held = SettlementType::canonical($profile->settlement_type);

        if ($target === null || $held === null) {
            return [0.0, false];
        }

        if ($target !== $held) {
            return [0.0, true];
        }

        $matched[] = SettlementType::label($target) . ' applicants';

        return [$share, true];
    }
}
