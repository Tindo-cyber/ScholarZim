<?php

namespace App\Services\ScholarFit;

use App\Support\EducationLevel;

/**
 * A deliberately small, deterministic reader for eligibility conditions
 * stated in a listing's title or free-text description, rather than in a
 * structured requirement field.
 *
 * This exists because an empty structured-requirements table does not mean a
 * listing has no eligibility conditions. A title like "Undergraduate
 * Scholarship" or a description sentence like "This scholarship is open to
 * students pursuing a bachelor's degree" states one just as clearly as a
 * structured minimum_education_level would - a provider who never touched
 * the requirement builder has still said who the award is for, and reading
 * nothing there and calling every applicant eligible is exactly the false
 * positive this class exists to close. Education level is the concept this
 * was built for; field of study and a short list of named skills are read
 * the same way because the mechanism is identical.
 *
 * This is pattern matching against a fixed, small vocabulary, not natural
 * language understanding.
 *
 * The title is read without needing an eligibility marker: a title is short
 * and definitional by convention, and every example this was built against
 * - "Undergraduate Scholarship", "Master's Scholarship", "PhD Scholarship" -
 * states its audience with no surrounding sentence to carry one, so a title
 * level always reads as EDUCATION_LEVEL (see that constant). The description
 * is read more carefully: a sentence there only becomes a condition when it
 * also contains one of two short lists of explicit markers -
 * ENTRY_REQUIREMENT_MARKER_PATTERNS ("must have", "requires", ...) or
 * TARGET_MARKER_PATTERNS ("for ... students", "is open to", ...), which
 * decide whether an education-level phrase in that sentence reads as
 * ENTRY_QUALIFICATION or EDUCATION_LEVEL respectively - "Previous
 * undergraduate research experience is an advantage" names a level without
 * matching either list, and is deliberately left alone. Within a marked
 * sentence, only phrases from a small curated vocabulary - built on
 * EducationLevel's own canonical values and FormOptions::FIELDS_OF_STUDY's
 * own values - are read as conditions this system can actually check; a
 * recognised skill/technology phrase is read as a condition too, but
 * flagged unsupported rather than evaluated, because the applicant profile
 * has no field to check it against. Everything else in a marked sentence
 * produces nothing at all.
 */
final class DescriptionEligibility
{
    public const EDUCATION_LEVEL = 'education_level';

    /**
     * A level the description states as a prerequisite an applicant must
     * already hold - "requires A-Level", "must have an A-Level
     * qualification" - as opposed to EDUCATION_LEVEL, which names who the
     * award is *for* in the softer, audience sense ("for A-Level students",
     * "open to..."). The distinction matters because they are evaluated
     * differently: see EligibilityEvaluator::descriptionEntryQualification()
     * vs ::descriptionEducationLevel().
     */
    public const ENTRY_QUALIFICATION = 'entry_qualification';

    public const FIELD_OF_STUDY = 'field_of_study';

    public const UNSUPPORTED = 'unsupported';

    /**
     * Regex fragments, each already word-bounded, that state a strict
     * prerequisite - "you must already hold this" - rather than describing
     * who the award is generally for. A sentence matching one of these
     * reads its education-level phrase as ENTRY_QUALIFICATION, checked with
     * the same "at least this level, or evidence of it" floor
     * minimum_education_level uses, not the progression-toward comparison
     * EDUCATION_LEVEL gets. Deliberately narrow and literal ("requires",
     * "must have") rather than inferred from softer audience language
     * ("open to", "for ... students") - see TARGET_MARKER_PATTERNS below,
     * which stays on the progression comparison. Not applied to the title -
     * see the class docblock.
     */
    private const ENTRY_REQUIREMENT_MARKER_PATTERNS = [
        '/\bmust\s+be\b/',
        '/\bmust\s+have\b/',
        '/\brequired\s+to\b/',
        '/\brequires?\b/',
        '/\b(?:applicants?|candidates?)\s+must\b/',
    ];

    /**
     * Regex fragments that make a *description* sentence a statement about
     * who may apply, in the softer "this is the intended audience" sense -
     * an applicant progressing toward the named level is still read as
     * meeting it. See ENTRY_REQUIREMENT_MARKER_PATTERNS above for the
     * stricter "must already hold this" phrasing, which is not here.
     *
     * Several allow a bounded gap ("for ... students") so phrasing like "for
     * secondary school students" matches without the marker itself having to
     * name every level that can sit in the gap.
     */
    private const TARGET_MARKER_PATTERNS = [
        '/\bintended\s+for\b/',
        '/\bopen\s+to\b/',
        '/\bonly\s+for\b/',
        '/\bfor\s+\S.{0,40}?\b(?:students?|applicants?|candidates?)\b/',
        '/\beligible\s+applicants?\b/',
        '/\b(?:students?|applicants?|candidates?)\s+(?:pursuing|currently\s+enrolled|enrolled|completing|studying)\b/',
        '/\bpursuing\s+an?\b/',
        '/\bcurrently\s+enrolled\b/',
        '/\bsupports?\s+(?:students?|applicants?)\b/',
        '/\baimed\s+at\s+(?:students?|applicants?)\b/',
    ];

    /**
     * Words that turn a statement of who is welcome into one about who is not.
     *
     * "This award is not open to Master's students" has the marker ("open to") and
     * the level ("master's") of a statement that it IS for Master's students, and
     * reading it that way is the opposite of what it says. A negation word in the
     * same clause as the marker, or as a level or field phrase, means that phrase is
     * not a statement of audience.
     *
     * It is dropped, never inverted. "Not Master's" does not mean "everyone else",
     * and guessing that is the kind of inference a small deterministic reader must
     * not make. Whole-word, so "Norton" and "notably" are not negations; `n't`
     * covers isn't, can't, won't and the rest.
     */
    private const NEGATION_PATTERN = '/\b(?:not|no|non|nor|never|none|cannot|except|excluding|excludes?|excluded|ineligible)\b|\bother\s+than\b|n\'t\b/';

    /**
     * A negation word just before a phrase in a TITLE, which has no sentence around
     * it to carry a marker: "(excluding PhD)", "Non-Postgraduate Support Fund",
     * "Not For Undergraduates". Only a few filler words may sit between the two, so
     * "No Fee Undergraduate Scholarship" - where the "no" is about money - still
     * states its audience.
     */
    private const TITLE_NEGATION_LEAD = '(?:not|non|no|never|excluding|excludes?|except|other\s+than)\s+(?:(?:for|to|open|be|an?|the|those|students?|applicants?|candidates?|of)\s+){0,3}';

    /**
     * Phrase => the canonical EducationLevel it names. Shared by both
     * EDUCATION_LEVEL and ENTRY_QUALIFICATION conditions - the phrase
     * vocabulary is identical; only the sentence's marker (see above)
     * decides which comparison EligibilityEvaluator runs against it.
     *
     * "High school"/"secondary school" map to O_LEVEL: the system has no
     * single "secondary" constant, and EducationLevel::LEGACY_MAP already
     * treats a bare "secondary" the same way. As an EDUCATION_LEVEL
     * condition this feeds the progression comparison in
     * EligibilityEvaluator::descriptionEducationLevel() - the same one
     * `progression()`'s advisory note makes via EducationPathway, not the
     * "at least this level" floor a structured minimum_education_level
     * uses - so an applicant already at or past secondary school still
     * clears "for secondary school students", and a Primary applicant
     * reaches it too, through the Form 1 step EducationPathway already
     * recognises as the ordinary next one.
     */
    private const EDUCATION_LEVEL_PHRASES = [
        'primary school' => EducationLevel::PRIMARY,
        'primary education' => EducationLevel::PRIMARY,
        'primary' => EducationLevel::PRIMARY,

        // Form 1 is the entry year of secondary school: "Form 1 Transition
        // Bursary" states its audience by name the same way "Undergraduate
        // Scholarship" does. Read as FORM_1, the scholarship-only entry level,
        // which EligibilityEvaluator::descriptionEducationLevel() holds to
        // Primary pupils moving up - every other level has finished Form 1.
        'form 1' => EducationLevel::FORM_1,
        'form one' => EducationLevel::FORM_1,

        'high school' => EducationLevel::O_LEVEL,
        'secondary school' => EducationLevel::O_LEVEL,
        'secondary education' => EducationLevel::O_LEVEL,
        'secondary students' => EducationLevel::O_LEVEL,
        // The literal level names, not just the "secondary" umbrella above -
        // "O-Level Scholarship" states a level by name the same way
        // "Undergraduate Scholarship" does, and needs its own entry because
        // normalise() only folds hyphens to spaces, it does not expand "O"/
        // "A" into "ordinary"/"advanced".
        'o level' => EducationLevel::O_LEVEL,
        'ordinary level' => EducationLevel::O_LEVEL,
        'a level' => EducationLevel::A_LEVEL,
        'advanced level' => EducationLevel::A_LEVEL,

        'undergraduate degree' => EducationLevel::UNDERGRADUATE,
        'undergraduate student' => EducationLevel::UNDERGRADUATE,
        'undergraduate' => EducationLevel::UNDERGRADUATE,
        // The same word as providers actually type it. Word-boundary
        // matching means "undergraduate" alone never matches the plural
        // ("Scholarship for Undergraduates"), and normalise() turns the
        // hyphenated "Under-graduate" into two words - each of these used to
        // read as no condition at all, so a Primary applicant was offered
        // the award as though it were open to everyone.
        'undergraduates' => EducationLevel::UNDERGRADUATE,
        'under graduate' => EducationLevel::UNDERGRADUATE,
        'under graduates' => EducationLevel::UNDERGRADUATE,
        'undergrad' => EducationLevel::UNDERGRADUATE,
        'undergrads' => EducationLevel::UNDERGRADUATE,
        "bachelor's degree" => EducationLevel::UNDERGRADUATE,
        "bachelor's" => EducationLevel::UNDERGRADUATE,
        // "bachelor" and "bachelors" are listed separately, not just the
        // singular: word-boundary matching means the singular alone never
        // matches inside the plural (the "s" fails the boundary check), and
        // the apostrophe-free plural is at least as common in real listing
        // titles as the possessive spelling.
        'bachelors' => EducationLevel::UNDERGRADUATE,
        'bachelor' => EducationLevel::UNDERGRADUATE,
        'first degree' => EducationLevel::UNDERGRADUATE,

        // Unlike "university"/"polytechnic" (DESCRIPTION_ONLY_EDUCATION_LEVEL_PHRASES
        // below), "diploma" does not plausibly name an institution rather
        // than a level, so it is safe in the title too.
        'diploma' => EducationLevel::DIPLOMA,

        "master's degree" => EducationLevel::MASTERS,
        "master's" => EducationLevel::MASTERS,
        'master of science' => EducationLevel::MASTERS,
        'master of arts' => EducationLevel::MASTERS,
        // Same reasoning as "bachelors" above: "masters" (no apostrophe) is
        // at least as common a spelling as "master's" in real titles.
        'masters' => EducationLevel::MASTERS,
        'master' => EducationLevel::MASTERS,
        'mba' => EducationLevel::MASTERS,
        'msc' => EducationLevel::MASTERS,
        'postgraduate student' => EducationLevel::POSTGRADUATE,
        'postgraduate' => EducationLevel::POSTGRADUATE,
        // Same plural/hyphenation gap as "undergraduates" above.
        'postgraduates' => EducationLevel::POSTGRADUATE,
        'post graduate' => EducationLevel::POSTGRADUATE,
        'post graduates' => EducationLevel::POSTGRADUATE,

        'doctoral student' => EducationLevel::PHD,
        'doctorate' => EducationLevel::PHD,
        'doctoral' => EducationLevel::PHD,
        'phd' => EducationLevel::PHD,
    ];

    /**
     * Phrase => the canonical EducationLevel it names, read only from a
     * marked *description* sentence - never from the title, and never
     * word-bounded into the title-safe EDUCATION_LEVEL_PHRASES above.
     *
     * "University"/"polytechnic" name an institution at least as often as
     * they name an audience: a provider's own name very plausibly contains
     * one ("Midlands State University Alumni Scholarship", "Harare
     * Polytechnic Trust Fund"), and the title is read with no marker
     * requirement at all (see the class docblock), so matching these there
     * would misread the provider's name as a stated eligibility condition.
     * A marked description sentence ("open to university students") is a
     * narrower, safer context - it is talking about who may apply, not
     * naming who is giving the award.
     *
     * "Polytechnic" maps to DIPLOMA, the closest existing EducationLevel:
     * Zimbabwean polytechnics award certificates and diplomas, and DIPLOMA
     * already sits directly below UNDERGRADUATE on EducationLadder/in
     * EducationPathway's table - the same rung a diploma-granting
     * institution occupies today. Not a new level; see
     * EducationLevel::TIER_TERTIARY.
     */
    private const DESCRIPTION_ONLY_EDUCATION_LEVEL_PHRASES = [
        'university' => EducationLevel::UNDERGRADUATE,
        'polytechnic' => EducationLevel::DIPLOMA,
    ];

    /**
     * Phrase => the canonical FormOptions::FIELDS_OF_STUDY value it names.
     * "Education" is deliberately absent: it collides with "education
     * level", a phrase already present in almost every sentence this class
     * reads, and a false match there would misread the applicant's whole
     * eligibility on every listing with an education-level condition.
     */
    private const FIELD_OF_STUDY_PHRASES = [
        'computer science' => 'Computer Science & IT',
        'information technology' => 'Computer Science & IT',
        'engineering' => 'Engineering',
        'medicine' => 'Medicine & Health Sciences',
        'health sciences' => 'Medicine & Health Sciences',
        'law' => 'Law',
        'business' => 'Business & Finance',
        'finance' => 'Business & Finance',
        'agriculture' => 'Agriculture & Agribusiness',
        'arts' => 'Arts & Humanities',
        'humanities' => 'Arts & Humanities',
        'natural sciences' => 'Natural Sciences',
        'social sciences' => 'Social Sciences',
        'nursing' => 'Nursing',
        'accounting' => 'Accounting',
        'environmental science' => 'Environmental Science',
        'mining' => 'Mining & Metallurgy',
    ];

    /**
     * Skill/technology phrases with no authoritative applicant-profile field
     * behind them. Detected so the condition can be reported, never
     * evaluated as a pass or a failure - see the class docblock.
     */
    private const UNSUPPORTED_PHRASES = [
        'php', 'python', 'java', 'javascript', 'sql',
        'programming skills', 'programming experience', 'programming knowledge',
        'coding skills', 'coding experience',
        'software development skills', 'software development experience',
    ];

    private function __construct()
    {
    }

    /**
     * Every condition the title or description states explicitly. Education
     * level is read from both fields and never deduplicated against itself
     * here - the same level named in both is two pieces of evidence for one
     * condition, and EligibilityEvaluator groups them back into one outcome
     * that names every source that actually contributed. Field of study and
     * skill/technology conditions are read from the description only, each
     * kept to one entry per distinct value regardless of how many sentences
     * name it.
     *
     * @return array<int, DescriptionCondition>
     */
    public static function conditions(?string $title, ?string $description): array
    {
        $found = [];

        if (filled($title)) {
            $normalisedTitle = self::normalise($title);

            foreach (self::matches($normalisedTitle, self::EDUCATION_LEVEL_PHRASES) as [$phrase, $level]) {
                if (self::negatedInTitle($normalisedTitle, $phrase)) {
                    continue;
                }

                $found[] = new DescriptionCondition(self::EDUCATION_LEVEL, $level, $phrase, DescriptionCondition::SOURCE_TITLE);
            }
        }

        if (blank($description)) {
            return $found;
        }

        $seen = [];

        foreach (self::sentences($description) as $sentence) {
            $normalised = self::normalise($sentence);

            // Entry-requirement phrasing ("requires A-Level") takes priority
            // over the softer audience phrasing ("open to A-Level
            // students") when a sentence happens to match both - the
            // stricter reading is the more specific, and therefore more
            // informative, one. A sentence matching neither carries no
            // condition at all, same as before this distinction existed.
            $educationLevelKind = match (true) {
                self::matchesAny($normalised, self::ENTRY_REQUIREMENT_MARKER_PATTERNS) => self::ENTRY_QUALIFICATION,
                self::matchesAny($normalised, self::TARGET_MARKER_PATTERNS) => self::EDUCATION_LEVEL,
                default => null,
            };

            if ($educationLevelKind === null) {
                continue;
            }

            // A sentence that says who is NOT welcome states no audience. Judged by
            // clause: a negation sitting beside the marker cancels the sentence, and one
            // sitting beside a phrase cancels that phrase - so "Open to undergraduate
            // students, no age limit applies" keeps its audience, and "Open to
            // undergraduates, but not postgraduates" keeps only the first.
            $clauses = self::clauses($normalised);

            if (self::markerIsNegated($clauses)) {
                continue;
            }

            $levelMatches = self::outsideNegation($clauses, array_merge(
                self::matches($normalised, self::EDUCATION_LEVEL_PHRASES),
                self::matches($normalised, self::DESCRIPTION_ONLY_EDUCATION_LEVEL_PHRASES),
            ));

            foreach ($levelMatches as [$phrase, $level]) {
                $found[] = new DescriptionCondition($educationLevelKind, $level, $phrase, DescriptionCondition::SOURCE_DESCRIPTION);
            }

            foreach (self::outsideNegation($clauses, self::matches($normalised, self::FIELD_OF_STUDY_PHRASES)) as [$phrase, $field]) {
                $found[] = self::once($seen, self::FIELD_OF_STUDY, $field, $phrase);
            }

            foreach (self::UNSUPPORTED_PHRASES as $phrase) {
                if (self::containsWord($normalised, $phrase) && ! self::phraseIsNegated($clauses, $phrase)) {
                    $found[] = self::once($seen, self::UNSUPPORTED, $phrase, $phrase);
                }
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * @param  array<string, bool>  $seen
     */
    private static function once(array &$seen, string $kind, string $value, string $phrase): ?DescriptionCondition
    {
        $key = $kind . ':' . $value;

        if (isset($seen[$key])) {
            return null;
        }

        $seen[$key] = true;

        return new DescriptionCondition($kind, $value, $phrase, DescriptionCondition::SOURCE_DESCRIPTION);
    }

    /** @return array<int, string> */
    private static function sentences(string $description): array
    {
        $pieces = preg_split('/(?<=[.!?])\s+|\r?\n+/', $description) ?: [$description];

        return array_values(array_filter(array_map('trim', $pieces), fn (string $s) => $s !== ''));
    }

    /**
     * The sentence split into clauses at commas, semicolons, colons and the joining
     * words that start a new thought ("but", "however", ...). Negation is judged
     * within a clause, so an unrelated "no" in another clause does not cancel a
     * statement of audience.
     *
     * @return array<int, string>
     */
    private static function clauses(string $normalisedSentence): array
    {
        $parts = preg_split('/[,;:]|\s+(?:but|however|although|though|while|whereas)\s+/', $normalisedSentence) ?: [$normalisedSentence];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $c) => $c !== ''));
    }

    private static function isNegated(string $clause): bool
    {
        return preg_match(self::NEGATION_PATTERN, $clause) === 1;
    }

    /** @param  array<int, string>  $clauses */
    private static function markerIsNegated(array $clauses): bool
    {
        foreach ($clauses as $clause) {
            $hasMarker = self::matchesAny($clause, self::ENTRY_REQUIREMENT_MARKER_PATTERNS)
                || self::matchesAny($clause, self::TARGET_MARKER_PATTERNS);

            if ($hasMarker && self::isNegated($clause)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $clauses */
    private static function phraseIsNegated(array $clauses, string $phrase): bool
    {
        foreach ($clauses as $clause) {
            if (self::containsWord($clause, $phrase) && self::isNegated($clause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The matches whose phrase is not in a negated clause.
     *
     * @param  array<int, string>  $clauses
     * @param  array<int, array{0: string, 1: string}>  $matches
     * @return array<int, array{0: string, 1: string}>
     */
    private static function outsideNegation(array $clauses, array $matches): array
    {
        return array_values(array_filter(
            $matches,
            static fn (array $match) => ! self::phraseIsNegated($clauses, $match[0])
        ));
    }

    private static function negatedInTitle(string $normalisedTitle, string $phrase): bool
    {
        return preg_match(
            '/(?<![a-z0-9])' . self::TITLE_NEGATION_LEAD . '(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/',
            $normalisedTitle
        ) === 1;
    }

    /** @param  array<int, string>  $patterns */
    private static function matchesAny(string $normalisedSentence, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalisedSentence) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $phrases
     * @return array<int, array{0: string, 1: string}> [matched phrase, mapped value]
     */
    private static function matches(string $normalisedText, array $phrases): array
    {
        $matches = [];

        foreach ($phrases as $phrase => $value) {
            if (self::containsWord($normalisedText, $phrase)) {
                $matches[] = [$phrase, $value];
            }
        }

        return $matches;
    }

    /** Whole-word/phrase containment, so "law" does not match inside "flaw" or "outlawed". */
    private static function containsWord(string $haystack, string $phrase): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])' . preg_quote($phrase, '/') . '(?![a-z0-9])/', $haystack);
    }

    /**
     * Lower-cased, with curly apostrophes and hyphens folded so "Master's",
     * "Master’s" and "high-school" all read the same as the phrase table
     * spells them.
     */
    private static function normalise(string $text): string
    {
        $clean = strtolower(trim($text));
        $clean = str_replace(["\u{2018}", "\u{2019}"], "'", $clean);
        $clean = str_replace('-', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;

        return trim($clean);
    }
}
