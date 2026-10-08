<?php

namespace App\Services;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Services\ScholarFit\Taxonomy\SettlementType;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use App\Support\FormOptions;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\ProviderDrafts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saving a listing before it is ready.
 *
 * A draft is stored as the form stands. It is not validated the way a submission
 * is: the rules between fields, the required fields, the ceilings, the risk
 * checker, publishing and telling the administrators all wait for submit. What a
 * draft does keep is every value that CAN be stored - including values a
 * submission would reject, because "minimum level above the target" is an
 * unfinished thought, not a reason to lose it.
 *
 * The one thing it must never do is lose something quietly. A value that cannot
 * be stored (not a number, not a date, not one of the offered options, too big
 * for its column) is left out, and save() returns exactly which field and why, so
 * the provider is told on the next screen rather than finding it gone on the one
 * after.
 *
 * Only the title is required, because a draft with no name cannot be found again.
 */
class ListingDraftService
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * @param  array<string, mixed>  $input  the form as posted
     * @return array{draft: Opportunity, notKept: array<int, array{field: string, reason: string}>}
     */
    public function save(array $input, User $provider, ?int $draftId = null): array
    {
        $draft = null;

        if ($draftId !== null) {
            $draft = Opportunity::query()
                ->where('opportunity_id', $draftId)
                ->where('provider_user_id', $provider->user_id)
                ->where('moderation_status', OpportunityModerationStatus::DRAFT)
                ->first();

            // Not a draft of theirs: this route must never be a way to overwrite a
            // live listing, or somebody else's.
            abort_if($draft === null, 404);
        } elseif (ProviderDrafts::atLimit($provider)) {
            throw new RuntimeException(ProviderDrafts::limitMessage());
        }

        $notKept = [];
        $attributes = $this->attributes($input, $provider, $notKept);

        $draft = DB::transaction(function () use ($draft, $attributes, $input, $provider, &$notKept) {
            if ($draft === null) {
                $draft = Opportunity::create($attributes + [
                    'provider_user_id' => $provider->user_id,
                    'status' => OpportunityStatus::ACTIVE,
                    'moderation_status' => OpportunityModerationStatus::DRAFT,
                    'created_at' => Carbon::now(),
                ]);
            } else {
                $draft->update($attributes + ['updated_at' => Carbon::now()]);
            }

            $this->syncSubjects($draft, $input['subject_requirements'] ?? [], $notKept);

            return $draft;
        });

        return ['draft' => $draft, 'notKept' => $notKept];
    }

    /** Throw a draft away. Nothing references one - no applications, reports or notices - so it is deleted outright. */
    public function discard(Opportunity $draft, User $provider): void
    {
        abort_unless($draft->isDraft() && $draft->provider_user_id === $provider->user_id, 404);

        DB::transaction(function () use ($draft, $provider) {
            $title = $draft->title;
            $id = $draft->opportunity_id;

            $draft->subjectRequirements()->delete();
            $draft->delete();

            $this->auditService->logOrFail(
                $provider->email,
                AuditAction::DISCARD_DRAFT,
                'OPPORTUNITY',
                $id,
                'Discarded draft "' . $title . '"'
            );
        });
    }

    // ---------------------------------------------------------- the values --

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array{field: string, reason: string}>  $notKept
     * @return array<string, mixed>
     */
    private function attributes(array $input, User $provider, array &$notKept): array
    {
        $lose = function (string $field, string $reason) use (&$notKept): void {
            $notKept[] = ['field' => $field, 'reason' => $reason];
        };

        $text = function (string $key, string $label, int $max) use ($input, $lose): ?string {
            $value = $input[$key] ?? null;

            if (! filled($value) || ! is_scalar($value)) {
                return null;
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > $max) {
                $lose($label, 'It is too long (' . mb_strlen($value) . ' characters; the most that can be saved is ' . $max . ').');

                return null;
            }

            return $value;
        };

        $option = function (string $key, string $label, array $allowed, string $noun) use ($input, $lose): ?string {
            $value = $input[$key] ?? null;

            if (! filled($value) || ! is_scalar($value)) {
                return null;
            }

            if (! in_array((string) $value, $allowed, true)) {
                $lose($label, '"' . $this->shown($value) . '" is not one of the ' . $noun . ' offered.');

                return null;
            }

            return (string) $value;
        };

        $integer = function (string $key, string $label, int $min, int $max) use ($input, $lose): ?int {
            $value = $input[$key] ?? null;

            if (! filled($value) || ! is_scalar($value)) {
                return null;
            }

            if (! is_numeric($value) || (float) $value !== floor((float) $value) || (float) $value < $min || (float) $value > $max) {
                $lose($label, '"' . $this->shown($value) . '" is not a whole number between ' . $min . ' and ' . $max . '.');

                return null;
            }

            return (int) $value;
        };

        $boolean = static fn (string $key): bool => in_array(strtolower((string) ($input[$key] ?? '')), ['1', 'true', 'on', 'yes'], true);

        $own = trim((string) $provider->full_name);
        $behalf = $text('provider_display_name', 'Awarding on behalf of', 255);

        // Amount: a number that fits decimal(12,2).
        $amount = null;
        $rawAmount = $input['award_amount'] ?? null;

        if (filled($rawAmount) && is_scalar($rawAmount)) {
            if (! is_numeric($rawAmount)) {
                $lose('Award value', '"' . $this->shown($rawAmount) . '" is not a number.');
            } elseif (abs((float) $rawAmount) >= 10_000_000_000) {
                $lose('Award value', '"' . $this->shown($rawAmount) . '" is too large to store.');
            } else {
                $amount = round((float) $rawAmount, 2);
            }
        }

        $currency = $option('award_currency', 'Currency', FormOptions::CURRENCIES, 'currencies');

        // A date the picker would have sent: Y-m-d, and a real day.
        $deadline = null;
        $rawDeadline = $input['deadline'] ?? null;

        if (filled($rawDeadline) && is_scalar($rawDeadline)) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', trim((string) $rawDeadline));

            if ($parsed === false || $parsed->format('Y-m-d') !== trim((string) $rawDeadline)) {
                $lose('Application deadline', '"' . $this->shown($rawDeadline) . '" is not a date that can be read. Use the date picker.');
            } else {
                $deadline = $parsed->format('Y-m-d');
            }
        }

        $country = $option('country', 'Country', FormOptions::COUNTRIES, 'countries');

        if ($country === null && filled($input['country'] ?? null)) {
            $lose('Country', 'It was replaced by ' . FormOptions::DEFAULT_COUNTRY . ', the default, which you can change.');
        }

        return [
            'title' => $text('title', 'Scholarship title', 255),
            'description' => $text('description', 'Full description', 65000),
            'provider_name' => $own,
            'on_behalf_of' => ($behalf !== null && strcasecmp($behalf, $own) !== 0) ? $behalf : null,
            'education_level' => $option('education_level', 'Level of study', EducationLevel::TARGET_LEVELS, 'levels'),
            'minimum_education_level' => $option('minimum_education_level', 'Minimum qualifying level', EducationLevel::APPLICANT_LEVELS, 'levels'),
            'target_field' => $text('target_field', 'Field of study', 255),
            'funding_type' => $option('funding_type', 'Funding type', FormOptions::FUNDING_TYPES, 'funding types'),
            'country' => $country ?? FormOptions::DEFAULT_COUNTRY,
            'target_country' => $country ?? FormOptions::DEFAULT_COUNTRY,
            'deadline' => $deadline,
            'award_amount' => $amount,
            'award_currency' => $amount === null ? null : ($currency ?? FormOptions::DEFAULT_CURRENCY),
            'award_slots' => $integer('award_slots', 'Number of awards', 0, 65535),
            'is_renewable' => $boolean('is_renewable'),
            'external_url' => $text('external_url', 'Application page link', 500),
            'min_academic_points' => $integer('min_academic_points', 'Minimum A-Level points', 0, 255),
            'max_age' => $integer('max_age', 'Maximum age', 0, 255),
            'required_province' => $option('required_province', 'Required province', FormOptions::ZIMBABWE_PROVINCES, 'provinces'),
            'target_locality' => $text('target_locality', 'Target locality', 100),
            'target_settlement_type' => $option('target_settlement_type', 'Settlement type', SettlementType::ALL, 'settlement types'),
            'requires_results_certificate' => $boolean('requires_results_certificate'),
            // A draft has been checked by nothing, and says nothing about itself.
            'risk_flags' => null,
            'auto_approved' => false,
        ];
    }

    /**
     * Keep every subject row that can be kept, and say what happened to the rest.
     *
     * @param  mixed  $rows
     * @param  array<int, array{field: string, reason: string}>  $notKept
     */
    private function syncSubjects(Opportunity $draft, mixed $rows, array &$notKept): void
    {
        $keptIds = [];
        $firstRowFor = [];
        $position = 0;

        foreach (is_array($rows) ? array_values($rows) : [] as $row) {
            $row = is_array($row) ? $row : [];

            // A row the provider added and never touched is not a loss.
            if (! filled($row['qualification_id'] ?? null) && ! filled($row['subject_id'] ?? null)) {
                $position++;

                continue;
            }

            $position++;
            $label = 'Subject requirements - Row ' . $position;

            $qualification = is_numeric($row['qualification_id'] ?? null) ? AcademicQualification::find((int) $row['qualification_id']) : null;
            $subject = is_numeric($row['subject_id'] ?? null) ? AcademicSubject::find((int) $row['subject_id']) : null;

            if ($qualification === null) {
                $notKept[] = ['field' => $label, 'reason' => 'No qualification was chosen, so the row was not kept.'];

                continue;
            }

            if ($subject === null) {
                $notKept[] = ['field' => $label, 'reason' => 'No subject was chosen, so the row was not kept.'];

                continue;
            }

            if ((int) $subject->qualification_id !== (int) $qualification->id) {
                $notKept[] = ['field' => $label, 'reason' => $subject->label() . ' is not offered under ' . $qualification->name . ', so the row was not kept.'];

                continue;
            }

            if (isset($firstRowFor[$subject->id])) {
                $notKept[] = ['field' => $label, 'reason' => $subject->label() . ' is already in row ' . $firstRowFor[$subject->id] . ', so this row was not kept.'];

                continue;
            }

            $firstRowFor[$subject->id] = $position;

            $grade = null;

            if (filled($row['minimum_grade'] ?? null)) {
                $grade = $subject->canonicalGrade($row['minimum_grade']);

                if ($grade === null) {
                    $notKept[] = [
                        'field' => $label,
                        'reason' => '"' . $this->shown($row['minimum_grade']) . '" is not a grade this subject awards ('
                            . implode(', ', $subject->grades()) . '). The subject was kept without a grade.',
                    ];
                }
            }

            $requirement = OpportunitySubjectRequirement::updateOrCreate(
                ['opportunity_id' => $draft->opportunity_id, 'subject_id' => $subject->id],
                ['qualification_id' => $qualification->id, 'minimum_grade' => $grade]
            );

            $keptIds[] = $requirement->id;
        }

        $draft->subjectRequirements()->whereNotIn('id', $keptIds)->delete();
    }

    /** A value as it can safely be shown back in a message. */
    private function shown(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return mb_strlen($text) > 40 ? mb_substr($text, 0, 37) . '...' : $text;
    }
}
