<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\OpportunitySubjectRequirement;

/**
 * An earlier listing, as the starting point for a new one.
 *
 * Providers post the same bursary every intake. Duplicating prefills the create
 * form with everything the provider WROTE - the offer, the rules, the subject
 * requirements - and leaves everything that happened to the original behind:
 * whether it was approved or flagged or auto-published, who reviewed it and when,
 * what students reported about it, how many people viewed it. None of that is
 * about the new listing, and carrying it over would let a copy inherit a clean
 * bill of health it has not earned (or a reputation it has not earned).
 *
 * That is why this builds from an ALLOWLIST of fields rather than replicating the
 * row and blanking what is unwanted: a column added later is left behind by
 * default, and has to be chosen to be copied.
 *
 * The result is never saved. It only fills a form; saving goes through the same
 * create path as any new listing, which decides review, runs the risk checker
 * and sets the moderation state from scratch.
 */
final class ListingTemplate
{
    /** What a provider writes, and therefore what a copy starts with. */
    private const COPIED = [
        'title',
        'description',
        'education_level',
        'minimum_education_level',
        'target_field',
        'funding_type',
        'country',
        'on_behalf_of',
        'award_amount',
        'award_currency',
        'award_slots',
        'is_renewable',
        'external_url',
        'min_academic_points',
        'max_age',
        'required_province',
        'target_locality',
        'target_settlement_type',
        'requires_results_certificate',
    ];

    private function __construct()
    {
    }

    /** An unsaved listing carrying the original's content, a new title, and no deadline. */
    public static function copyOf(Opportunity $source): Opportunity
    {
        $copy = new Opportunity();

        foreach (self::COPIED as $field) {
            $copy->setAttribute($field, $source->getAttribute($field));
        }

        $copy->title = self::nextYearTitle((string) $source->title, (int) now()->year);

        // A deadline belongs to an intake. The copy is for the next one, so it has
        // none until the provider chooses it - never the old date, which is past.
        $copy->deadline = null;

        $requirements = $source->subjectRequirements->map(function (OpportunitySubjectRequirement $requirement) {
            $row = new OpportunitySubjectRequirement([
                'qualification_id' => $requirement->qualification_id,
                'subject_id' => $requirement->subject_id,
                'minimum_grade' => $requirement->minimum_grade,
            ]);

            // The form shows the subject by name, which it reads from the relation.
            $row->setRelation('qualification', $requirement->qualification);
            $row->setRelation('subject', $requirement->subject);

            return $row;
        });

        $copy->setRelation('subjectRequirements', $requirements);

        // Who it is open to carries over; the form shows each choice by name, from the relations.
        $copy->setRelation('scopes', $source->scopes->map(function (OpportunityScope $scope) {
            $row = new OpportunityScope([
                'programme_id' => $scope->programme_id,
                'field_id' => $scope->field_id,
                'institution_id' => $scope->institution_id,
            ]);
            $row->setRelation('programme', $scope->programme);
            $row->setRelation('field', $scope->field);
            $row->setRelation('institution', $scope->institution);

            return $row;
        }));

        return $copy;
    }

    /**
     * The title for the next intake: the last year in it moved on, or the coming
     * year added when there is none.
     *
     * "Next" never means a year already gone: a listing last run in 2024 and
     * copied now is for this year's intake, not 2025's. Only a standalone
     * 20xx counts as a year, so "Fund 12345" is not mistaken for one.
     */
    public static function nextYearTitle(string $title, int $currentYear): string
    {
        $title = trim($title);

        if (preg_match_all('/(?<!\d)20\d{2}(?!\d)/', $title, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            [$year, $offset] = end($matches[0]);
            $next = max(((int) $year) + 1, $currentYear);

            return substr($title, 0, $offset) . $next . substr($title, $offset + 4);
        }

        return ($title === '' ? '' : $title . ' ') . ($currentYear + 1);
    }
}
