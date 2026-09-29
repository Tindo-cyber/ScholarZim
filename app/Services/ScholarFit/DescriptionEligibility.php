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
 * states its audience with no surrounding sentence to carry one. The
 * description is read more carefully: a sentence there only becomes a
 * condition when it also contains one of a short list of explicit
 * eligibility markers ("must be", "for ... students", "is open to", ...) -
 * "Previous undergraduate research experience is an advantage" names a level
 * without stating a condition, and is deliberately left alone. Within a
 * marked sentence, only phrases from a small curated vocabulary - built on
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

    public const FIELD_OF_STUDY = 'field_of_study';

    public const UNSUPPORTED = 'unsupported';

    /**
     * Regex fragments, each already word-bounded, that make a *description*
     * sentence a statement about who may apply rather than a description of
     * the award, its research area, or an applicant's advantage. Not applied
     * to the title - see the class docblock.
     *
     * Several allow a bounded gap ("for ... students") so phrasing like "for
     * secondary school students" matches without the marker itself having to
     * name every level that can sit in the gap.
     */
    private const ELIGIBILITY_MARKER_PATTERNS = [
        '/\bmust\s+be\b/',
        '/\bmust\s+have\b/',
        '/\brequired\s+to\b/',
        '/\brequires?\b/',
        '/\bintended\s+for\b/',
        '/\bopen\s+to\b/',
        '/\bonly\s+for\b/',
        '/\bfor\s+\S.{0,40}?\b(?:students?|applicants?|candidates?)\b/',
        '/\b(?:applicants?|candidates?)\s+must\b/',
        '/\beligible\s+applicants?\b/',
        '/\b(?:students?|applicants?|candidates?)\s+(?:pursuing|currently\s+enrolled|enrolled|completing|studying)\b/',
        '/\bpursuing\s+an?\b/',
        '/\bcurrently\s+enrolled\b/',
        '/\bsupports?\s+(?:students?|applicants?)\b/',
        '/\baimed\s+at\s+(?:students?|applicants?)\b/',
    ];

    /**
     * Phrase => the canonical EducationLevel it names.
     *
     * "High school"/"secondary school" map to O_LEVEL: the system has no
     * single "secondary" constant, and EducationLevel::LEGACY_MAP already
     * treats a bare "secondary" the same way. This feeds a progression
     * comparison in EligibilityEvaluator::descriptionEducationLevel() - the
     * same one `progression()`'s advisory note makes via EducationPathway,
     * not the "at least this level" floor a structured
     * minimum_education_level uses - so an applicant already at or past
     * secondary school still clears "for secondary school students", and a
     * Primary applicant reaches it too, through the Form 1 step
     * EducationPathway already recognises as the ordinary next one.
     */
    private const EDUCATION_LEVEL_PHRASES = [
        'primary school' => EducationLevel::PRIMARY,
        'primary education' => EducationLevel::PRIMARY,
        'primary' => EducationLevel::PRIMARY,

        'high school' => EducationLevel::O_LEVEL,
        'secondary school' => EducationLevel::O_LEVEL,
        'secondary education' => EducationLevel::O_LEVEL,
        'secondary students' => EducationLevel::O_LEVEL,

        'undergraduate degree' => EducationLevel::UNDERGRADUATE,
        'undergraduate student' => EducationLevel::UNDERGRADUATE,
        'undergraduate' => EducationLevel::UNDERGRADUATE,
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

        'doctoral student' => EducationLevel::PHD,
        'doctorate' => EducationLevel::PHD,
        'doctoral' => EducationLevel::PHD,
        'phd' => EducationLevel::PHD,
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
            foreach (self::matches(self::normalise($title), self::EDUCATION_LEVEL_PHRASES) as [$phrase, $level]) {
                $found[] = new DescriptionCondition(self::EDUCATION_LEVEL, $level, $phrase, DescriptionCondition::SOURCE_TITLE);
            }
        }

        if (blank($description)) {
            return $found;
        }

        $seen = [];

        foreach (self::sentences($description) as $sentence) {
            $normalised = self::normalise($sentence);

            if (! self::hasEligibilityMarker($normalised)) {
                continue;
            }

            foreach (self::matches($normalised, self::EDUCATION_LEVEL_PHRASES) as [$phrase, $level]) {
                $found[] = new DescriptionCondition(self::EDUCATION_LEVEL, $level, $phrase, DescriptionCondition::SOURCE_DESCRIPTION);
            }

            foreach (self::matches($normalised, self::FIELD_OF_STUDY_PHRASES) as [$phrase, $field]) {
                $found[] = self::once($seen, self::FIELD_OF_STUDY, $field, $phrase);
            }

            foreach (self::UNSUPPORTED_PHRASES as $phrase) {
                if (self::containsWord($normalised, $phrase)) {
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

    private static function hasEligibilityMarker(string $normalisedSentence): bool
    {
        foreach (self::ELIGIBILITY_MARKER_PATTERNS as $pattern) {
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
