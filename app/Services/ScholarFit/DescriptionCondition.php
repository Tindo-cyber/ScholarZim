<?php

namespace App\Services\ScholarFit;

/** One eligibility condition read out of a listing's title or description text. */
final class DescriptionCondition
{
    public const SOURCE_TITLE = 'title';

    public const SOURCE_DESCRIPTION = 'description';

    public function __construct(
        /** One of DescriptionEligibility::EDUCATION_LEVEL, ::FIELD_OF_STUDY, ::UNSUPPORTED. */
        public readonly string $kind,
        /**
         * For EDUCATION_LEVEL: the canonical EducationLevel value.
         * For FIELD_OF_STUDY: the canonical FormOptions::FIELDS_OF_STUDY value.
         * For UNSUPPORTED: the matched phrase itself, verbatim.
         */
        public readonly string $value,
        /** The phrase actually matched, for the explanation. */
        public readonly string $matchedPhrase,
        /** Which field this was read from: SOURCE_TITLE or SOURCE_DESCRIPTION. */
        public readonly string $source,
    ) {
    }
}
