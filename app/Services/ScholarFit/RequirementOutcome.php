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
     * Always advisory. A recognised progression is reported so an applicant can
     * see it was considered, and an unusual one is reported so they know it is
     * unusual - but neither decides anything. Whether a particular scholarship
     * accepts a particular applicant is answered by the requirements that
     * scholarship actually states, not by a table of what usually follows what.
     */
    public const TYPE_PROGRESSION = 'progression';

    public const TYPE_EDUCATION_LEVEL = 'education_level';

    public const TYPE_POINTS = 'points';

    public const TYPE_SUBJECT = 'subject';

    public const TYPE_AGE = 'age';

    public const TYPE_PROVINCE = 'province';

    public const TYPE_CERTIFICATE = 'certificate';

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
    ): self {
        return new self($type, true, $message, $qualificationKey, $qualificationName, $subject, $required, $actual);
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
        return array_values(array_filter($outcomes, static fn (self $o) => ! $o->passed && ! $o->advisory));
    }

    /**
     * @param  array<int, self>  $outcomes
     * @return array<int, self>
     */
    public static function passes(array $outcomes): array
    {
        return array_values(array_filter($outcomes, static fn (self $o) => $o->passed && ! $o->advisory));
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

    /** @param array<int, self> $outcomes */
    public static function allMet(array $outcomes): bool
    {
        return self::failures($outcomes) === [];
    }
}
