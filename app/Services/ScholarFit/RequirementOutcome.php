<?php

namespace App\Services\ScholarFit;

/**
 * One hard requirement a listing states, and how this applicant stands against
 * it - carrying the required value, the applicant's actual value, and whether
 * it passed.
 *
 * The evaluator used to return only the sentences for requirements an
 * applicant failed. Two things were impossible as a result. An eligible
 * applicant could not be shown what they had met, because nothing recorded it.
 * And a failure could not name the numbers that produced it, because by the
 * time the sentence existed the values were gone - which is how "your results
 * do not meet them: Mathematics" came to be the whole explanation for a
 * student holding a D against a required C.
 *
 * Both halves are kept here: `required` and `actual` for anything that reads
 * this as data, `message` for anything that shows it to a person. One outcome
 * per requirement, so three failed subjects are three sentences rather than
 * one list that names none of the grades.
 *
 * A requirement the provider did not state produces no outcome at all. Silence
 * is not a pass, and reporting it as one is what made a previous version tell
 * applicants they matched requirements that did not exist.
 */
final class RequirementOutcome
{
    /**
     * How the applicant's current level relates to what the listing funds.
     *
     * Advisory, with one exception. A recognised progression is reported so an
     * applicant can see it was considered, and an unusual one so they know it is
     * unusual - but neither decides anything: whether a scholarship accepts an
     * applicant is answered by the requirements it states, not by a table of what
     * usually follows what. The exception is a jump nobody makes (a Grade 7 pupil
     * for a diploma, an O-Level student for a PhD), which LevelJump reports as a
     * failed requirement.
     */
    public const TYPE_PROGRESSION = 'progression';

    public const TYPE_EDUCATION_LEVEL = 'education_level';

    public const TYPE_POINTS = 'points';

    public const TYPE_SUBJECT = 'subject';

    public const TYPE_AGE = 'age';

    public const TYPE_PROVINCE = 'province';

    public const TYPE_CERTIFICATE = 'certificate';

    /** A town the listing is for, checked against the applicant's own locality. */
    public const TYPE_LOCALITY = 'locality';

    /** Rural or urban, checked against the applicant's settlement type. */
    public const TYPE_SETTLEMENT = 'settlement_type';

    /** The field of study the listing states in its structured field. */
    public const TYPE_FIELD = 'field_of_study';

    /**
     * An eligibility condition read from the listing's free-text description
     * rather than from a structured requirement field - only used when no
     * structured requirement already covers the same concept, so the two
     * never disagree with each other.
     */
    public const TYPE_DESCRIPTION_EDUCATION_LEVEL = 'description_education_level';

    /**
     * A qualification the description states an applicant must already
     * hold to enter - "requires A-Level" - read with the same floor
     * comparison a structured minimum_education_level uses, not the
     * progression-toward comparison TYPE_DESCRIPTION_EDUCATION_LEVEL gets.
     * See DescriptionEligibility::ENTRY_QUALIFICATION.
     */
    public const TYPE_DESCRIPTION_ENTRY_QUALIFICATION = 'description_entry_qualification';

    public const TYPE_DESCRIPTION_FIELD = 'description_field';

    /** The programmes and fields a listing is open to (see ProgrammeScope). */
    public const TYPE_PROGRAMME_SCOPE = 'programme_scope';

    /** The institutions a listing is limited to (see ProgrammeScope). */
    public const TYPE_INSTITUTION_SCOPE = 'institution_scope';

    /** How closely the applicant's programme matched: used only to order listings, never shown as a number. */
    public const FIT_PROGRAMME = 'programme';

    public const FIT_NARROW_FIELD = 'narrow_field';

    public const FIT_BROAD_FIELD = 'broad_field';

    /**
     * A condition the description states explicitly, but which the
     * applicant profile has no authoritative field to check - e.g. a named
     * programming language. Always advisory: reported so the applicant sees
     * it was noticed, never counted as a pass or a failure, because doing
     * either would be inventing evidence the profile does not have.
     */
    public const TYPE_DESCRIPTION_UNSUPPORTED = 'description_unsupported';

    /**
     * A condition the provider listed for students to check for themselves (IELTS, work experience). Always
     * advisory, like TYPE_DESCRIPTION_UNSUPPORTED: the profile has nothing to check it against.
     */
    public const TYPE_PROVIDER_CONDITION = 'provider_condition';

    /**
     * Wording in the title or description that sounds like an education restriction but cannot be read reliably (see
     * DescriptionEligibility::UNRESOLVED_LEVEL). A review outcome: the listing is not called open to everyone and the
     * applicant is not turned away; they are told what could not be read and asked to check with the provider.
     */
    public const TYPE_DESCRIPTION_UNRESOLVED = 'description_unresolved';

    private function __construct(
        public readonly string $type,
        public readonly bool $passed,
        public readonly string $message,
        public readonly ?string $qualificationKey = null,
        public readonly ?string $qualificationName = null,
        public readonly ?string $subject = null,
        public readonly string|int|float|null $required = null,
        public readonly string|int|float|null $actual = null,
        /**
         * Reported, but not a rule. An advisory outcome never makes an
         * applicant ineligible however it lands, so `failures()` skips it and
         * the score is unaffected. Used for the progression note, which
         * describes what usually follows what rather than what this listing
         * requires.
         */
        public readonly bool $advisory = false,
        /**
         * The applicant's profile has no answer to this question, so it cannot be
         * checked. Neither a pass nor a failure: the applicant is not excluded for
         * a blank they may never have been told mattered, and the listing is not
         * presented as a match nobody tested. `missing` names the profile field.
         */
        public readonly bool $needsInformation = false,
        public readonly ?string $missing = null,
        /** For a passed programme-scope rule: FIT_PROGRAMME, FIT_NARROW_FIELD or FIT_BROAD_FIELD. */
        public readonly ?string $fit = null,
        /**
         * A needs-information outcome the applicant cannot answer from their profile: the listing's own wording could not be
         * read. It keeps the listing out of the matches (nothing was confirmed) without making it impossible to apply to.
         */
        public readonly bool $review = false,
    ) {
    }

    public static function pass(
        string $type,
        string $message,
        ?string $qualificationKey = null,
        ?string $qualificationName = null,
        ?string $subject = null,
        string|int|float|null $required = null,
        string|int|float|null $actual = null,
        ?string $fit = null,
    ): self {
        return new self($type, true, $message, $qualificationKey, $qualificationName, $subject, $required, $actual, false, false, null, $fit);
    }

    public static function fail(
        string $type,
        string $message,
        ?string $qualificationKey = null,
        ?string $qualificationName = null,
        ?string $subject = null,
        string|int|float|null $required = null,
        string|int|float|null $actual = null,
    ): self {
        return new self($type, false, $message, $qualificationKey, $qualificationName, $subject, $required, $actual);
    }

    /**
     * A note about the applicant's progression: shown, but never a rule.
     *
     * `$recognised` says whether this is a usual next step, not whether the
     * applicant may apply. An unusual progression is worth telling someone
     * about; refusing them for it, when the provider has stated no requirement
     * it breaches, is a different thing entirely.
     */
    public static function note(
        string $type,
        bool $recognised,
        string $message,
        string|int|float|null $required = null,
        string|int|float|null $actual = null,
    ): self {
        return new self($type, $recognised, $message, null, null, null, $required, $actual, true);
    }

    /**
     * A requirement that cannot be checked because the applicant's profile lacks
     * the answer.
     *
     * @param  string  $missing  the profile field, by its ScholarFitFieldNames name
     */
    public static function needsInfo(
        string $type,
        string $message,
        string $missing,
        string|int|float|null $required = null,
    ): self {
        return new self($type, false, $message, null, null, null, $required, null, false, true, $missing);
    }

    /**
     * A requirement the listing seems to state but that cannot be read reliably. Pending like any unanswered
     * requirement - so it is never counted as met - but nothing in the profile answers it, so it carries no field and
     * does not stop an application (see blockingPending()).
     */
    public static function review(string $type, string $message, string|int|float|null $required = null): self
    {
        return new self($type, false, $message, null, null, null, $required, null, false, true, null, null, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'passed' => $this->passed,
            'advisory' => $this->advisory,
            'message' => $this->message,
            'qualification_key' => $this->qualificationKey,
            'qualification' => $this->qualificationName,
            'subject' => $this->subject,
            'required' => $this->required,
            'actual' => $this->actual,
            'needs_information' => $this->needsInformation,
            'review' => $this->review,
            'missing' => $this->missing,
            'fit' => $this->fit,
        ];
    }

    // ------------------------------------------------------------ collections --

    /**
     * The outcomes that actually make an applicant ineligible.
     *
     * Advisory outcomes are excluded however they landed: they describe the
     * applicant's situation, they do not state a rule the listing set.
     *
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function failures(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => ! $o->passed && ! $o->advisory && ! $o->needsInformation));
    }

    /**
     * The requirements that could not be checked for want of an answer from the
     * profile. Not failures, and never counted as passes.
     *
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function pending(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => $o->needsInformation));
    }

    /**
     * The unanswered requirements the applicant can do something about: the ones a profile answer would settle. These are
     * what stops an application; a review outcome (the listing's wording could not be read) does not.
     *
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function blockingPending(array $outcomes): array
    {
        return array_values(array_filter(self::pending($outcomes), static fn (self $o) => ! $o->review));
    }

    /**
     * The profile fields behind them, each named once.
     *
     * @param  array<int, self>  $outcomes
     * @return array<int, string>
     */
    public static function missingFields(array $outcomes): array
    {
        return array_values(array_unique(array_map(
            static fn (self $o) => (string) $o->missing,
            self::blockingPending($outcomes)
        )));
    }

    /**
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function passes(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => $o->passed && ! $o->advisory && ! $o->needsInformation));
    }

    /**
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function notes(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => $o->advisory));
    }

    /**
     * The requirements a listing actually stated, advisory notes aside.
     *
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function rules(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => ! $o->advisory));
    }

    /**
     * @param  array<int, self>  $outcomes
     * @return array<int, string>
     */
    public static function messages(array $outcomes): array
    {
        return array_values(array_map(static fn (self $o) => $o->message, $outcomes));
    }

    /**
     * @param  array<int, self>  $outcomes
     * @return array<int, string>
     */
    public static function failureMessages(array $outcomes): array
    {
        return self::messages(self::failures($outcomes));
    }

    /**
     * Every stated requirement is met: none failed AND none is waiting on an answer.
     * An unchecked rule is not a met one.
     *
     * @param array<int, self> $outcomes
     */
    public static function allMet(array $outcomes): bool
    {
        return self::failures($outcomes) === [] && self::pending($outcomes) === [];
    }
}
