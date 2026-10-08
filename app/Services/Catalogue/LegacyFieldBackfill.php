<?php

namespace App\Services\Catalogue;

use App\Models\ApplicantProfile;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;

/**
 * Moves the old free-text field of study onto the catalogue.
 *
 * LISTINGS with an old `target_field` and no "Open to" rows of their own get rows, one per
 * catalogue field the old value means (LegacyFieldMap plus any alias an administrator has made).
 * They are marked source = 'legacy_field', so they can be undone and are never mistaken for a
 * provider's own choice. The old column is never touched, and a listing the provider already
 * scoped is never touched either. Running it again changes nothing.
 *
 * STUDENTS are not moved: an old field is a field, not a programme, and inventing the programme
 * would be inventing a fact about them. They are asked to choose their programme instead
 * (ProfileDataQuality::programmeNeedsChoosing), and their old field stands in for a field rule
 * until they do (ProgrammeScope).
 *
 * VALUES nothing knows are reported, with how many listings and students wrote them, for an
 * administrator to say what they mean.
 */
class LegacyFieldBackfill
{
    public const SOURCE = 'legacy_field';

    public function run(bool $dryRun = false): BackfillReport
    {
        $report = new BackfillReport();
        $unmapped = [];

        $listings = Opportunity::query()
            ->whereNotNull('target_field')->where('target_field', '!=', '')
            ->doesntHave('scopes')
            ->get(['opportunity_id', 'target_field']);

        foreach ($listings as $listing) {
            if (LegacyFieldMap::isSchoolLabel($listing->target_field)) {
                continue;
            }

            $codes = LegacyFieldMap::codesFor($listing->target_field);

            if ($codes === []) {
                $key = Programme::normalise($listing->target_field);
                $unmapped[$key]['example'] ??= trim($listing->target_field);
                $unmapped[$key]['listings'] = ($unmapped[$key]['listings'] ?? 0) + 1;

                continue;
            }

            $report->listingsMigrated++;

            if ($dryRun) {
                continue;
            }

            foreach (Field::whereIn('code', $codes)->get() as $field) {
                OpportunityScope::create([
                    'opportunity_id' => $listing->opportunity_id, 'field_id' => $field->id, 'source' => self::SOURCE,
                ]);
                $report->rowsCreated++;
            }
        }

        // Students: counted so the administrator sees the size of the problem.
        $students = ApplicantProfile::query()->whereNotNull('field_of_study')->where('field_of_study', '!=', '')->pluck('field_of_study');

        foreach ($students as $value) {
            if (LegacyFieldMap::isSchoolLabel($value) || LegacyFieldMap::codesFor($value) !== []) {
                continue;
            }

            $key = Programme::normalise($value);
            $unmapped[$key]['example'] ??= trim($value);
            $unmapped[$key]['students'] = ($unmapped[$key]['students'] ?? 0) + 1;
        }

        foreach ($unmapped as $key => $row) {
            $report->unmapped[] = ['key' => $key, 'example' => $row['example'], 'listings' => $row['listings'] ?? 0, 'students' => $row['students'] ?? 0];
        }

        usort($report->unmapped, fn ($a, $b) => ($b['listings'] + $b['students']) <=> ($a['listings'] + $a['students']) ?: strcmp($a['key'], $b['key']));

        return $report;
    }

    /** Remove what the migration made, and only that. @return int rows removed */
    public function undo(): int
    {
        return OpportunityScope::where('source', self::SOURCE)->delete();
    }
}
