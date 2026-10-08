<?php

namespace App\Services;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Services\Catalogue\ApplicantProgrammes;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\EducationLevel;
use Illuminate\Support\Carbon;

/**
 * Whether a profile agrees with itself, and whether its level is due a check.
 *
 * ScholarFit judges an applicant on what their profile says, so a profile that quietly
 * stopped being true (a Primary pupil who is now 17, A-Level results recorded under an
 * "O Level" profile) gives wrong answers with full confidence. These are warnings, never
 * blocks: the applicant is shown them with a link to the field, and nothing they have
 * stated is changed or ignored on their behalf.
 *
 * Only contradictions are reported. A fact that is merely missing is the completion
 * checklist's business, not this class's.
 */
final class ProfileDataQuality
{
    public const AGE = 'age_level';

    public const RESULTS = 'results_above_level';

    public const PROGRAMME = 'programme_level';

    private function __construct()
    {
    }

    /** @return array<int, array{code: string, field: string, message: string}> */
    public static function warnings(ApplicantProfile $profile): array
    {
        $warnings = [];
        $level = $profile->education_level;

        if (blank($level)) {
            return $warnings;
        }

        $age = $profile->ageOn(Carbon::today());

        if ($reason = EducationLevel::ageConsistencyReason($level, $age)) {
            $warnings[] = ['code' => self::AGE, 'field' => 'date_of_birth', 'message' => $reason];
        }

        if ($above = self::highestResultsLevelAbove($profile)) {
            $warnings[] = [
                'code' => self::RESULTS,
                'field' => 'education_level',
                'message' => 'You have ' . EducationLevel::label($above) . ' results recorded, but your current level says '
                    . EducationLevel::label($level) . '. If you have moved on, update your level; if the results were added by mistake, remove them.',
            ];
        }

        $current = ! $profile->exists ? null : $profile->programmeChoices()->where('kind', 'current')->with('programme')->first();

        if ($current?->programme !== null && $current->programme->education_level !== EducationLevel::canonical($level)) {
            $warnings[] = [
                'code' => self::PROGRAMME,
                'field' => 'programme-card',
                'message' => 'Your programme, ' . $current->programme->name . ', is at ' . $current->programme->levelLabel()
                    . ' level, but your current level says ' . EducationLevel::label($level) . '. Update whichever has changed.',
            ];
        }

        return $warnings;
    }

    /** The highest level among recorded results that sits above the stated level, or null. */
    private static function highestResultsLevelAbove(ApplicantProfile $profile): ?string
    {
        $stated = EducationLadder::rung(EducationLevel::canonical($profile->education_level));

        if ($stated === null) {
            return null;
        }

        $highest = null;
        $highestRung = $stated;

        foreach ($profile->academicResults as $result) {
            $level = EducationLevel::canonical($result->qualification?->education_level);
            $rung = EducationLadder::rung($level);

            if ($level !== null && $rung !== null && $rung > $highestRung) {
                $highest = $level;
                $highestRung = $rung;
            }
        }

        return $highest;
    }

    /**
     * An enrolled student who has not said which programme they are on. Their old free-text field
     * of study cannot be turned into a programme for them - that would be inventing a fact - so
     * they are asked, once, and it stops the moment they choose.
     */
    public static function programmeNeedsChoosing(ApplicantProfile $profile): bool
    {
        if (! $profile->exists || ApplicantProgrammes::modeFor($profile) !== ApplicantProgrammes::CURRENT) {
            return false;
        }

        return ! $profile->programmeChoices()->where('kind', ApplicantProgramme::CURRENT)->exists();
    }

    /**
     * Whether it is time to ask "is this still your current level?". Counts from the last
     * confirmation, or from when the profile was created if there has never been one.
     */
    public static function levelNeedsConfirming(ApplicantProfile $profile): bool
    {
        if (blank($profile->education_level)) {
            return false;
        }

        $since = $profile->education_level_confirmed_at ?? $profile->created_at;

        if ($since === null) {
            return false;
        }

        $days = max(1, (int) config('scholarzim.profile.confirm_level_after_days', 365));

        return Carbon::parse($since)->lt(Carbon::now()->subDays($days));
    }
}
