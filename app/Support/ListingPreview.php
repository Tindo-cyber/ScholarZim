<?php

namespace App\Support;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * An unsaved listing built from a half-filled form, to be shown as the public page.
 *
 * "Preview" has to work on a form that has not been validated - that is the point
 * of looking before submitting - so this is forgiving: a missing title becomes
 * "Untitled scholarship", an unparseable date is no date, a value outside a list
 * is ignored. It never saves, and never throws on what a person typed.
 *
 * It applies the same level clearing as saving does, so the preview shows what the
 * saved listing would, not a page with a field of study on a Form 1 award.
 */
final class ListingPreview
{
    private function __construct()
    {
    }

    /** @param  array<string, mixed>  $input */
    public static function build(array $input, User $provider): Opportunity
    {
        $level = $input['education_level'] ?? null;
        $level = in_array($level, EducationLevel::TARGET_LEVELS, true) ? $level : null;

        if (! empty($input['level_driven']) && $level !== null) {
            $input = OpportunityLevelRules::clearInapplicable($input, $level) + $input;
        }

        $text = static fn (string $key): ?string => filled($input[$key] ?? null) ? trim((string) $input[$key]) : null;
        $number = static fn (string $key): ?float => is_numeric($input[$key] ?? null) ? (float) $input[$key] : null;
        $integer = static fn (string $key): ?int => is_numeric($input[$key] ?? null) ? (int) $input[$key] : null;
        $oneOf = static fn (string $key, array $allowed): ?string => in_array($input[$key] ?? null, $allowed, true) ? $input[$key] : null;

        $own = trim((string) $provider->full_name);
        $behalf = $text('provider_display_name');

        $amount = $number('award_amount');

        $opportunity = new Opportunity();
        $opportunity->forceFill([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $own,
            'on_behalf_of' => ($behalf !== null && strcasecmp($behalf, $own) !== 0) ? $behalf : null,
            'title' => $text('title') ?? 'Untitled scholarship',
            'description' => $text('description'),
            'education_level' => $level,
            'minimum_education_level' => $oneOf('minimum_education_level', EducationLevel::APPLICANT_LEVELS),
            'target_field' => $text('target_field'),
            'funding_type' => $oneOf('funding_type', FormOptions::FUNDING_TYPES),
            'country' => $oneOf('country', FormOptions::COUNTRIES) ?? FormOptions::DEFAULT_COUNTRY,
            'deadline' => self::date($input['deadline'] ?? null),
            'award_amount' => $amount,
            'award_currency' => $amount === null ? null : ($oneOf('award_currency', FormOptions::CURRENCIES) ?? FormOptions::DEFAULT_CURRENCY),
            'award_slots' => $integer('award_slots'),
            'is_renewable' => ! empty($input['is_renewable']),
            'external_url' => $text('external_url'),
            'min_academic_points' => $integer('min_academic_points'),
            'max_age' => $integer('max_age'),
            'required_province' => $oneOf('required_province', FormOptions::ZIMBABWE_PROVINCES),
            'target_locality' => $text('target_locality'),
            'target_settlement_type' => $text('target_settlement_type'),
            'requires_results_certificate' => ! empty($input['requires_results_certificate']),
            // Shown as live so the page renders as an applicant would see it.
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'created_at' => Carbon::now(),
        ]);

        $opportunity->setRelation('provider', $provider);
        $opportunity->setRelation('subjectRequirements', self::requirements($input['subject_requirements'] ?? []));

        return $opportunity;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return \Illuminate\Support\Collection<int, OpportunitySubjectRequirement> */
    private static function requirements(mixed $rows)
    {
        $out = collect();

        foreach (is_array($rows) ? array_values($rows) : [] as $row) {
            if (! is_array($row) || ! is_numeric($row['qualification_id'] ?? null) || ! is_numeric($row['subject_id'] ?? null)) {
                continue;
            }

            $qualification = AcademicQualification::find((int) $row['qualification_id']);
            $subject = AcademicSubject::find((int) $row['subject_id']);

            if ($qualification === null || $subject === null) {
                continue;
            }

            $requirement = new OpportunitySubjectRequirement([
                'qualification_id' => $qualification->id,
                'subject_id' => $subject->id,
                'minimum_grade' => filled($row['minimum_grade'] ?? null) ? (string) $row['minimum_grade'] : null,
            ]);
            $requirement->setRelation('qualification', $qualification);
            $requirement->setRelation('subject', $subject);

            $out->push($requirement);
        }

        return $out;
    }
}
