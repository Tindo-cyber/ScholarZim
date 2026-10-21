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
 *                                      levels a school-leaver enters
 *   Primary or no level yet            none
 *
 * Where a school-leaver has applied or holds an offer is NOT kept up on the profile. ScholarFit needs it only to check a
 * scholarship the provider limited to named institutions (a school-leaver has no current institution to check), so it is
 * asked for on the application that needs it and saved by saveAppliedInstitutions(). Saving programmes never touches it.
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
     * @param  array<string, mixed>  $input  current_programme_id, current_institution_id, target_field_ids[],
     *                                       intended_programme_ids[], programme_suggestion, ..._field, ..._level
     * @throws ValidationException
     */
    public function save(ApplicantProfile $profile, User $user, array $input): void
    {
        $mode = self::modeFor($profile);

        // The programme itself is chosen as the "field of study" on the profile form (setCurrent()), not in this card, so a
        // submission that does not carry it leaves it as it is; the same for where they studied.
        $existing = ApplicantProgramme::where('profile_id', $profile->profile_id)->where('kind', self::CURRENT)->first();
        $currentId = filled($input['current_programme_id'] ?? null)
            ? (int) $input['current_programme_id']
            : (! array_key_exists('current_programme_id', $input) && $mode === self::CURRENT && $existing?->programme_id !== null ? (int) $existing->programme_id : null);
        $institutionId = array_key_exists('current_institution_id', $input)
            ? (filled($input['current_institution_id']) ? (int) $input['current_institution_id'] : null)
            : ($existing?->institution_id !== null ? (int) $existing->institution_id : null);
        $intended = array_values(array_unique(array_map('intval', array_filter((array) ($input['intended_programme_ids'] ?? []), 'is_scalar'))));
        $suggestion = trim((string) ($input['programme_suggestion'] ?? ''));
        $targets = array_values(array_unique(array_map('intval', array_filter((array) ($input['target_field_ids'] ?? []), 'is_scalar'))));

        $errors = [];

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

        if ($targets !== []) {
            if ($mode !== self::CURRENT) {
                $errors['target_field_ids'] = 'Fields to study next are for students already on a programme or holding a qualification. Choose the programmes you hope to study instead.';
            } elseif (count($targets) > \App\Models\ApplicantTargetField::MAX) {
                $errors['target_field_ids'] = 'Choose up to ' . \App\Models\ApplicantTargetField::MAX . ' fields - the ones you most want to study next.';
            } elseif (\App\Models\Field::whereIn('id', $targets)->count() !== count($targets)) {
                $errors['target_field_ids'] = 'Choose fields from the list.';
            }
        }

        if (count($chosen['intended']) > ApplicantProgramme::MAX_INTENDED) {
            $errors['intended_programme_ids'] = 'Choose up to ' . ApplicantProgramme::MAX_INTENDED . ' programmes - the ones you are most likely to apply for.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($profile, $chosen, $institutionId, $targets, $mode): void {
            // Two saves at once (a double click, a second tab, a script) would each delete and re-create, and
            // could interleave into more than the limit. Holding the profile row makes the second wait, then
            // replace the first, so what is stored is always exactly one submission.
            ApplicantProfile::whereKey($profile->profile_id)->lockForUpdate()->first();

            ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();
            \App\Models\ApplicantTargetField::where('profile_id', $profile->profile_id)->delete();

            if ($mode === self::CURRENT) {
                foreach ($targets as $id) {
                    \App\Models\ApplicantTargetField::create(['profile_id' => $profile->profile_id, 'field_id' => $id]);
                }
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

        if ($mode === self::CURRENT && $chosen['current'] !== null) {
            $this->syncFieldOfStudy($profile, $chosen['current']);
        }
    }

    /**
     * The programmes this person may choose as their field of study, as id => name: those at their own level in the
     * catalogue, plus any they suggested themselves that are waiting for approval.
     *
     * @return array<int, string>
     */
    public function optionsFor(ApplicantProfile $profile, User $user): array
    {
        $levels = self::levelsFor($profile);

        return $levels === []
            ? []
            : $this->catalogue->formOptions($user)['programmes']
                ->filter(fn ($programme) => in_array($programme->education_level, $levels, true))
                ->mapWithKeys(fn ($programme) => [$programme->id => $programme->name])
                ->all();
    }

    /**
     * Set (or clear) the programme an enrolled student or graduate is on, leaving where they studied and the fields they
     * want next as they were. The "field of study" is this programme: its name is written to the profile so the provider's
     * view, the completion checklist and ScholarFit's older field matching all read the same answer.
     *
     * @throws ValidationException when the programme is not one they may choose at their level
     */
    public function setCurrent(ApplicantProfile $profile, User $user, ?int $programmeId): void
    {
        if (self::modeFor($profile) !== self::CURRENT) {
            throw ValidationException::withMessages(['current_programme_id' => 'A programme is chosen once your level is Certificate or above. Set your level first.']);
        }

        $programme = null;

        if ($programmeId !== null) {
            $errors = [];
            $programme = $this->usable($programmeId, self::levelsFor($profile), $user, 'current_programme_id', $errors);

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        DB::transaction(function () use ($profile, $programme): void {
            ApplicantProfile::whereKey($profile->profile_id)->lockForUpdate()->first();

            $existing = ApplicantProgramme::where('profile_id', $profile->profile_id)->where('kind', self::CURRENT)->first();
            ApplicantProgramme::where('profile_id', $profile->profile_id)->where('kind', self::CURRENT)->delete();

            if ($programme !== null) {
                ApplicantProgramme::create([
                    'profile_id' => $profile->profile_id, 'programme_id' => $programme->id,
                    'kind' => self::CURRENT,
                    // Where they studied is the institution written on the profile, read against the catalogue.
                    'institution_id' => Institution::matching($profile->institution_name)?->id ?? $existing?->institution_id,
                ]);
            }
        });

        $programme !== null
            ? $this->syncFieldOfStudy($profile, $programme)
            : $profile->update(['field_of_study' => null]);
    }

    /**
     * Where an enrolled student studied is the institution they wrote on their profile: when it is one the catalogue knows,
     * the programme they are on is linked to it. One answer, given once, instead of the same thing asked twice.
     */
    public function syncInstitution(ApplicantProfile $profile): void
    {
        $institution = Institution::matching($profile->institution_name);

        if ($institution !== null) {
            ApplicantProgramme::where('profile_id', $profile->profile_id)->where('kind', self::CURRENT)->update(['institution_id' => $institution->id]);
        }
    }

    /** The profile's written field of study follows the programme chosen: one answer, not two that can disagree. */
    private function syncFieldOfStudy(ApplicantProfile $profile, Programme $programme): void
    {
        $profile->update(['field_of_study' => $programme->name]);
    }

    /**
     * Replace the institutions a school-leaver has applied to or holds an offer from.
     *
     * Asked for on an application that is limited to named institutions, never kept up on the profile. Only a
     * school-leaver has a list (an enrolled student's institution is where they study, set with their programme),
     * at most three, each an institution that is switched on. Whatever is already saved stays until this replaces it.
     *
     * @param  array<int, mixed>  $ids
     * @throws ValidationException
     */
    public function saveAppliedInstitutions(ApplicantProfile $profile, array $ids): void
    {
        $ids = $this->validateAppliedInstitutions($profile, $ids);

        DB::transaction(function () use ($profile, $ids): void {
            \App\Models\ApplicantInstitution::where('profile_id', $profile->profile_id)->delete();

            foreach ($ids as $id) {
                \App\Models\ApplicantInstitution::create(['profile_id' => $profile->profile_id, 'institution_id' => $id]);
            }
        });
    }

    /**
     * Check an answer to "which institutions have you applied to" without saving anything, so a caller can settle
     * every other part of a request before it writes any of it.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, int> the distinct, validated institution ids
     * @throws ValidationException
     */
    public function validateAppliedInstitutions(ApplicantProfile $profile, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_scalar'))));
        $key = 'applied_institution_ids';

        if (self::modeFor($profile) !== self::INTENDED) {
            throw ValidationException::withMessages([$key => 'Where you have applied is asked of school-leavers. If you are studying, say where with your programme.']);
        }

        if ($ids === []) {
            throw ValidationException::withMessages([$key => 'Choose at least one institution, or go back if you have not applied anywhere yet.']);
        }

        if (count($ids) > \App\Models\ApplicantInstitution::MAX) {
            throw ValidationException::withMessages([$key => 'Choose up to ' . \App\Models\ApplicantInstitution::MAX . ' institutions - the ones you have applied to or hold an offer from.']);
        }

        if (Institution::active()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([$key => 'Choose institutions from the list.']);
        }

        return $ids;
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
