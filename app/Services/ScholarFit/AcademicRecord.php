<?php

namespace App\Services\ScholarFit;

use App\Models\AcademicResult;
use App\Models\ApplicantProfile;
use App\Services\ScholarFit\Taxonomy\EducationLadder;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;

/**
 * What can be read out of an applicant's academic record, kept apart by the
 * qualification each fact came from.
 *
 * This class used to hold one integer, `points`, being the sum of every
 * derived point on the profile. That single number was the root of three
 * separate faults: a Cambridge A* and a ZIMSEC A-Level A both scored 12 and
 * were added together, nine O-Level symbols cleared any A-Level points bar a
 * provider could set, and a First Class degree counted toward an A-Level
 * total. None of those was a conversion anyone had decided on - they followed
 * from putting four grading systems on one scale and calling sum() on it.
 *
 * So there is no total here, only totals. `pointsFor()` answers for exactly
 * one qualification, and only ZIMSEC A-Level has points at all. Asking for
 * the points of a qualification that awards none returns null, which callers
 * must read as "this qualification is not counted in points" rather than as
 * zero.
 *
 * Built once per evaluation and handed to both the eligibility layer and the
 * scoring matchers, so the points an applicant is refused for are the same
 * points a matcher awards them for.
 *
 * The free-text parser that preceded this is gone. It read a number out of
 * whatever an applicant had typed - "14 points at A-Level" - which made the
 * applicant the author of their own score. Results are facts now: a
 * qualification, a subject, a grade, and points the platform derives.
 */
final class AcademicRecord
{
    /**
     * @param  array<string, array<int, AcademicResult>>  $resultsByQualification  qualification_key => results
     * @param  array<string, float>  $pointsByQualification  qualification_key => derived total
     * @param  array<string, string>  $qualificationNames  qualification_key => display name
     * @param  array<string, AcademicResult>  $resultsBySubject  "qualificationId:subjectId" => result
     */
    private function __construct(
        private readonly array $resultsByQualification,
        private readonly array $pointsByQualification,
        private readonly array $qualificationNames,
        private readonly array $resultsBySubject,
        public readonly ?string $degreeClassification,
        /** @var array<string, string> canonical education level => the qualification name evidencing it */
        private readonly array $levelsHeld = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], [], null, []);
    }

    public static function fromProfile(ApplicantProfile $profile): self
    {
        if ($profile->profile_id === null) {
            return new self([], [], [], [], $profile->degree_classification, []);
        }

        $results = $profile->relationLoaded('academicResults')
            ? $profile->academicResults
            : $profile->academicResults()->with(['qualification', 'subject.qualification'])->get();

        $byQualification = [];
        $points = [];
        $names = [];
        $bySubject = [];
        $levelsHeld = [];

        foreach ($results as $result) {
            $qualification = $result->qualification;

            if ($qualification === null) {
                continue;
            }

            $key = $qualification->qualification_key;

            $byQualification[$key][] = $result;
            $names[$key] = $qualification->name;
            $bySubject[$result->qualification_id.':'.$result->subject_id] = $result;

            // A recorded result is evidence the applicant sat that
            // qualification, whatever their profile currently says their level
            // is. It is what lets "requires A-Level" be answered from what they
            // actually hold rather than from a dropdown they last touched
            // months ago.
            $level = EducationLevel::canonical($qualification->education_level);

            if ($level !== null) {
                $levelsHeld[$level] = $qualification->name;
            }

            // Only a qualification that awards points contributes to a total,
            // and only to its own. A null here is not zero: it is the record
            // saying this qualification is not measured in points.
            $subjectPoints = $result->points();

            if ($subjectPoints !== null && $qualification->awardsPoints()) {
                $points[$key] = ($points[$key] ?? 0.0) + $subjectPoints;
            }
        }

        // A degree classification is evidence of an undergraduate qualification
        // just as much as a set of subject results is evidence of an A-Level.
        if (filled($profile->degree_classification)) {
            $levelsHeld[EducationLevel::UNDERGRADUATE] ??= 'your degree classification';
        }

        return new self($byQualification, $points, $names, $bySubject, $profile->degree_classification, $levelsHeld);
    }

    /**
     * The qualification evidencing a level at or above the one asked for, or
     * null when the applicant has recorded nothing that reaches it.
     *
     * Ranked on the same ladder EducationMatcher scores with, so a Masters
     * holder satisfies "requires at least Undergraduate" without every level
     * in between needing its own row.
     */
    public function qualificationAtOrAbove(?string $level): ?string
    {
        $wanted = EducationLevel::canonical($level);

        if ($wanted === null) {
            return null;
        }

        $wantedRung = EducationLadder::rung($wanted);

        if ($wantedRung === null) {
            return null;
        }

        foreach ($this->levelsHeld as $held => $qualificationName) {
            $heldRung = EducationLadder::rung($held);

            if ($heldRung !== null && $heldRung >= $wantedRung) {
                return $qualificationName;
            }
        }

        return null;
    }

    /** Whether the applicant has recorded a qualification reaching this level. */
    public function holdsQualificationAtOrAbove(?string $level): bool
    {
        return $this->qualificationAtOrAbove($level) !== null;
    }

    /** @return array<int, string> the canonical levels the applicant has evidence for */
    public function levelsHeld(): array
    {
        return array_keys($this->levelsHeld);
    }

    /**
     * Points held under one qualification, or null when the applicant holds
     * nothing under it or it awards no points.
     *
     * Null is the answer that lets an evaluator say "add your A-Level results
     * so we can check" instead of refusing someone for a total of zero they
     * never claimed.
     */
    public function pointsFor(string $qualificationKey): ?float
    {
        return $this->pointsByQualification[$qualificationKey] ?? null;
    }

    /** The only points total this product has a rule about. */
    public function zimsecALevelPoints(): ?float
    {
        return $this->pointsFor(AcademicCatalogue::ZIMSEC_A_LEVEL);
    }

    /** @return array<int, AcademicResult> */
    public function resultsFor(string $qualificationKey): array
    {
        return $this->resultsByQualification[$qualificationKey] ?? [];
    }

    /** The applicant's result in one catalogue subject, or null if they did not sit it. */
    public function resultForSubject(int $qualificationId, int $subjectId): ?AcademicResult
    {
        return $this->resultsBySubject[$qualificationId.':'.$subjectId] ?? null;
    }

    public function hasQualification(string $qualificationKey): bool
    {
        return isset($this->resultsByQualification[$qualificationKey]);
    }

    /** @return array<int, string> */
    public function qualificationKeys(): array
    {
        return array_keys($this->resultsByQualification);
    }

    public function qualificationName(string $qualificationKey): string
    {
        return $this->qualificationNames[$qualificationKey]
            ?? AcademicCatalogue::qualification($qualificationKey)['name']
            ?? $qualificationKey;
    }

    /** Whether the applicant has recorded any academic fact at all. */
    public function isPresent(): bool
    {
        return $this->resultsByQualification !== [] || $this->degreeClassification !== null;
    }

    public function subjectCount(): int
    {
        return count($this->resultsBySubject);
    }

    /** Whether an A-Level points bar can be tested against this record at all. */
    public function hasComparableALevelPoints(): bool
    {
        return $this->zimsecALevelPoints() !== null;
    }

    /**
     * Strength on the record's own terms, for listings that set no points
     * floor, as a fraction of the academic weight.
     *
     * Graded on ZIMSEC A-Level points where the applicant has them, since
     * that is the only scale this product measures. An applicant whose
     * qualifications are unpointed - O-Level, Cambridge, Primary - is read on
     * how much of a record they have rather than being scored against a scale
     * their board does not use.
     */
    public function standaloneStrength(): float
    {
        $config = config('scholarfit.academic');

        if (! $this->isPresent()) {
            return 0.0;
        }

        $points = $this->zimsecALevelPoints();

        if ($points !== null) {
            return match (true) {
                $points >= (float) $config['strong_points'] => (float) $config['strong_record'],
                $points >= (float) $config['sound_points'] => (float) $config['sound_record'],
                default => (float) $config['thin_record'],
            };
        }

        if ($this->degreeClassification !== null && $this->subjectCount() === 0) {
            return (float) $config['sound_record'];
        }

        // An unpointed record is judged on its completeness, not converted on
        // to a scale its qualification does not have.
        return $this->subjectCount() >= (int) $config['sound_subjects']
            ? (float) $config['sound_record']
            : (float) $config['thin_record'];
    }

    /** How the record reads in a one-line explanation. */
    public function summary(): string
    {
        if (! $this->isPresent()) {
            return 'No academic results on your profile';
        }

        $parts = [];

        $points = $this->zimsecALevelPoints();

        if ($points !== null) {
            $parts[] = self::formatPoints($points).' ZIMSEC A-Level points';
        }

        foreach ($this->qualificationKeys() as $key) {
            if ($key === AcademicCatalogue::ZIMSEC_A_LEVEL && $points !== null) {
                continue;
            }

            $count = count($this->resultsFor($key));
            $parts[] = $count.' '.$this->qualificationName($key).' '.($count === 1 ? 'result' : 'results');
        }

        if ($this->degreeClassification !== null) {
            $parts[] = $this->degreeClassification;
        }

        return implode('; ', $parts);
    }

    /** Points read back as a whole number where they are one, which they always are today. */
    public static function formatPoints(float $points): string
    {
        return $points == (int) $points
            ? (string) (int) $points
            : rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
    }
}
