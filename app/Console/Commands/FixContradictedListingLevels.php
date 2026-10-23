<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Services\AuditService;
use App\Services\ScholarFit\DescriptionConflicts;
use App\Services\ScholarFit\DescriptionEligibility;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\OpportunityLevelRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrects saved listings whose "Level of study" contradicts the level their own title names - the shape
 * "Madrid Scholarship for A level student" was saved in: titled for A-Level, stored as O Level.
 *
 * Eligibility already treats such a listing strictly (EligibilityEvaluator keeps the title's reading), so nobody is
 * wrongly matched while it waits; this puts the stored data right so the card, detail page and checks all say one thing.
 *
 * It only touches a listing whose title names ONE level. Dry run by default; nothing is written without --apply.
 * Where the title names an A-Level award and the description says it is for O-Level students, the minimum qualifying
 * level is set to O Level as well - that is what the description already states, written where it is checked.
 */
class FixContradictedListingLevels extends Command
{
    protected $signature = 'scholarzim:fix-contradicted-listing-levels {--id=* : Only these opportunity ids} {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Find (and with --apply, correct) listings whose level of study contradicts their title';

    public function handle(AuditService $audit): int
    {
        $query = Opportunity::query()->whereNotNull('education_level');

        if ($ids = array_filter((array) $this->option('id'))) {
            $query->whereIn('opportunity_id', $ids);
        }

        $found = 0;

        foreach ($query->get() as $listing) {
            $contradicting = DescriptionConflicts::contradictingTitleConditions($listing);
            $named = array_values(array_unique(array_map(static fn ($c) => $c->value, $contradicting)));

            if ($named === []) {
                continue;
            }

            $found++;

            if (count($named) !== 1) {
                $this->warn("#{$listing->opportunity_id} \"{$listing->title}\": title names several levels - fix by hand.");

                continue;
            }

            $changes = ['education_level' => $named[0]];

            if ($named[0] === EducationLevel::A_LEVEL && blank($listing->minimum_education_level) && $this->descriptionSaysOLevel($listing)) {
                $changes['minimum_education_level'] = EducationLevel::O_LEVEL;
            }

            if (OpportunityLevelRules::minimumLevelProblem($changes['minimum_education_level'] ?? $listing->minimum_education_level, $named[0])) {
                $this->warn("#{$listing->opportunity_id} \"{$listing->title}\": the change would clash with its minimum level - fix by hand.");

                continue;
            }

            $this->line(sprintf(
                '#%d "%s": %s -> %s%s',
                $listing->opportunity_id,
                $listing->title,
                EducationLevel::label($listing->education_level),
                EducationLevel::label($named[0]),
                isset($changes['minimum_education_level']) ? ', minimum qualifying level -> '.EducationLevel::label(EducationLevel::O_LEVEL) : ''
            ));

            if (! $this->option('apply')) {
                continue;
            }

            DB::transaction(function () use ($listing, $changes, $audit) {
                $old = $listing->only(array_keys($changes));
                $listing->forceFill($changes)->save();

                $audit->logOrFail(
                    'system',
                    AuditAction::UPDATE_OPPORTUNITY,
                    'Opportunity',
                    $listing->opportunity_id,
                    'Level of study corrected to match the listing title',
                    ['old' => $old, 'new' => $changes, 'reason' => 'Title and level of study contradicted each other']
                );
            });
        }

        $this->info($found === 0
            ? 'No listing contradicts its title.'
            : ($this->option('apply') ? "Corrected where possible ({$found} found)." : "{$found} found. Re-run with --apply to write the changes."));

        return self::SUCCESS;
    }

    private function descriptionSaysOLevel(Opportunity $listing): bool
    {
        foreach (DescriptionEligibility::conditions(null, $listing->description) as $condition) {
            if ($condition->kind === DescriptionEligibility::EDUCATION_LEVEL && $condition->value === EducationLevel::O_LEVEL) {
                return true;
            }
        }

        return false;
    }
}
