<?php

namespace App\Services\Catalogue;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which programme an applicant is on, or hopes to be on.
 *
 *   enrolled (Certificate and above)   one current programme, at their own level, and where
 *   school-leaver (O-Level, A-Level)   up to three programmes they hope to study next, at the
 *                                      levels a school-leaver enters, and optionally up to three
 *                                      institutions they have applied to or hold an offer from
 *   Primary or no level yet            none
 *
 * A programme is chosen from the catalogue. One that is not listed is suggested (and waits for
 * an administrator) but can be used by the person who suggested it straight away.
 */
class ApplicantProgrammes
{
    public const NONE = 'none';

    public const CURRENT = ApplicantProgramme::CURRENT;

    public const INTENDED = ApplicantProgramme::INTENDED;

    /** The levels a school-leaver can hope to enter. */
    public const LEAVER_LEVELS = [EducationLevel::CERTIFICATE, EducationLevel::DIPLOMA, EducationLevel::UNDERGRADUATE];

    public function __construct(private readonly ProgrammeCatalogue $catalogue)
    {
    }

    /** What this profile can record: `current`, `intended` or `none`. */
    public static function modeFor(ApplicantProfile $profile): string
    {
        $level = EducationLevel::canonical($profile->education_level);

        return match (true) {
            in_array($level, Programme::LEVELS, true) => self::CURRENT,
            in_array($level, [EducationLevel::O_LEVEL, EducationLevel::A_LEVEL], true) => self::INTENDED,
            default => self::NONE,
        };
    }

    /** The levels of programme this profile may choose from. @return array<int, string> */
    public static function levelsFor(ApplicantProfile $profile): array
    {
        return match (self::modeFor($profile)) {
            self::CURRENT => [EducationLevel::canonical($profile->education_level)],
            self::INTENDED => self::LEAVER_LEVELS,
            default => [],
        };
    }

    /**
     * Replace the applicant's programmes with what the form said.
     *
     * @param  array<string, mixed>  $input  current_programme_id, current_institution_id,
     *                                       intended_programme_ids[], programme_suggestion, ..._field, ..._level
     * @throws ValidationException
     */
    public function save(ApplicantProfile $profile, User $user, array $input): void
    {
        $mode = self::modeFor($profile);
        $currentId = filled($input['current_programme_id'] ?? null) ? (int) $input['current_programme_id'] : null;
        $institutionId = filled($input['current_institution_id'] ?? null) ? (int) $input['current_institution_id'] : null;
        $intended = array_values(array_unique(array_map('intval', array_filter((array) ($input['intended_programme_ids'] ?? []), 'is_scalar'))));
        $suggestion = trim((string) ($input['programme_suggestion'] ?? ''));
        $applied = array_values(array_unique(array_map('intval', array_filter((array) ($input['applied_institution_ids'] ?? []), 'is_scalar'))));

        $errors = [];

        if ($applied && $mode !== self::INTENDED) {
            $errors['applied_institution_ids'] = $mode === self::CURRENT
                ? 'Say where you study with your current programme above. This list is for school-leavers.'
                : 'Institutions you have applied to only apply once you are at O-Level or A-Level.';
        }

        if ($mode === self::NONE && ($currentId || $intended || $suggestion !== '')) {
            $errors['intended_programme_ids'] = 'Programmes do not apply at your current level. Set your level first, then choose a programme.';
        } elseif ($mode === self::INTENDED && $currentId) {
            $errors['current_programme_id'] = 'You have not started a programme yet. Choose the ones you hope to study instead.';
        } elseif ($mode === self::CURRENT && $intended) {
            $errors['intended_programme_ids'] = 'You are on a programme already. Choose it as your current programme.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $levels = self::levelsFor($profile);
        $chosen = ['current' => null, 'intended' => []];

        if ($currentId !== null) {
            $chosen['current'] = $this->usable($currentId, $levels, $user, 'current_programme_id', $errors);
        }

        foreach ($intended as $i => $id) {
            $programme = $this->usable($id, $levels, $user, 'intended_programme_ids', $errors);

            if ($programme !== null) {
                $chosen['intended'][] = $programme;
            }
        }

        if ($institutionId !== null && ! Institution::active()->whereKey($institutionId)->exists()) {
            $errors['current_institution_id'] = 'Choose an institution from the list.';
        }

        if ($suggestion !== '' && $mode !== self::NONE) {
            $suggested = $this->suggested($profile, $user, $suggestion, $input, $mode, $errors);

            if ($suggested !== null) {
                $mode === self::CURRENT ? $chosen['current'] = $suggested : $chosen['intended'][] = $suggested;
            }
        }

        $chosen['intended'] = collect($chosen['intended'])->unique('id')->values()->all();

        if ($applied && ! isset($errors['applied_institution_ids'])) {
            if (count($applied) > \App\Models\ApplicantInstitution::MAX) {
                $errors['applied_institution_ids'] = 'Choose up to ' . \App\Models\ApplicantInstitution::MAX . ' institutions - the ones you have applied to or hold an offer from.';
            } elseif (Institution::active()->whereIn('id', $applied)->count() !== count($applied)) {
                $errors['applied_institution_ids'] = 'Choose institutions from the list.';
            }
        }

        if (count($chosen['intended']) > ApplicantProgramme::MAX_INTENDED) {
            $errors['intended_programme_ids'] = 'Choose up to ' . ApplicantProgramme::MAX_INTENDED . ' programmes - the ones you are most likely to apply for.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($profile, $chosen, $institutionId, $applied): void {
            ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();
            \App\Models\ApplicantInstitution::where('profile_id', $profile->profile_id)->delete();

            foreach ($applied as $id) {
                \App\Models\ApplicantInstitution::create(['profile_id' => $profile->profile_id, 'institution_id' => $id]);
            }

            if ($chosen['current'] !== null) {
                ApplicantProgramme::create([
                    'profile_id' => $profile->profile_id, 'programme_id' => $chosen['current']->id,
                    'kind' => self::CURRENT, 'institution_id' => $institutionId,
                ]);
            }

            foreach ($chosen['intended'] as $programme) {
                ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $programme->id, 'kind' => self::INTENDED]);
            }
        });
    }

    /** A programme the person may choose at one of these levels, or null with the reason recorded. */
    private function usable(int $id, array $levels, User $user, string $key, array &$errors): ?Programme
    {
        $programme = Programme::find($id);

        $visible = $programme !== null && (
            ($programme->status === Programme::APPROVED && $programme->is_active)
            || ($programme->isPending() && $programme->suggested_by === $user->user_id)
        );

        if (! $visible) {
            $errors[$key] = 'That programme is not in the catalogue.';

            return null;
        }

        if (! in_array($programme->education_level, $levels, true)) {
            $errors[$key] = $programme->name . ' is at ' . $programme->levelLabel() . ' level, which is not a level you can choose at your stage.';

            return null;
        }

        return $programme;
    }

    private function suggested(ApplicantProfile $profile, User $user, string $name, array $input, string $mode, array &$errors): ?Programme
    {
        $level = EducationLevel::canonical($profile->education_level);

        if ($mode === self::INTENDED) {
            $level = EducationLevel::canonical((string) ($input['programme_suggestion_level'] ?? ''));

            if (! in_array($level, self::LEAVER_LEVELS, true)) {
                $errors['programme_suggestion_level'] = 'Choose the level of the programme: Certificate, Diploma or Undergraduate.';

                return null;
            }
        }

        $fieldId = (int) ($input['programme_suggestion_field'] ?? 0);

        if (! Field::whereKey($fieldId)->exists()) {
            $errors['programme_suggestion_field'] = 'Choose the field the programme belongs to.';

            return null;
        }

        try {
            return $this->catalogue->suggest($user, $name, (string) $level, $fieldId);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $errors['programme_suggestion'] = $e->getMessage();

            return null;
        }
    }
}
