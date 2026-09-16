<?php

namespace App\Support\Academic;

/**
 * A qualification's grading system: which result symbols it recognises, the
 * order they rank in, and - only where the qualification genuinely has one -
 * the points each symbol is worth.
 *
 * Separating rank from points is the entire purpose of this class. The first
 * implementation stored one flat `symbol => points` map and used it for both
 * jobs at once, which forced every qualification to invent a point scale it
 * does not have merely to be able to answer "is a B better than a C?". Two
 * things followed from that, both wrong: ZIMSEC O-Level acquired a summable
 * scale it has never had, and a Cambridge A* and a ZIMSEC A-Level A both
 * landed on 12 and were added to each other.
 *
 * Here `grades` is an ordered list, best first, and a symbol's index in it is
 * its rank - that answers every comparison question, for every qualification,
 * without implying a total. `points` is optional, and in this product it is
 * present on exactly one qualification: ZIMSEC A-Level.
 *
 * Comparison is always within a single qualification. Nothing in this class
 * can compare a grade under one scheme against a grade under another, which
 * is what keeps Cambridge results from being silently converted into ZIMSEC
 * points.
 */
final class GradingScheme
{
    /** Letter or symbol grades: A, B, C / A*, a, 9. */
    public const TYPE_GRADE = 'grade';

    /** Non-graded assessment units, e.g. Grade 7 unit marks. */
    public const TYPE_ASSESSMENT = 'assessment';

    /** @var array<string, int> upper-cased symbol => rank, built once on construction */
    private array $rankLookup;

    /** @var array<string, string> upper-cased symbol => canonical symbol */
    private array $canonicalLookup;

    /**
     * @param  array<int, string>  $grades  ordered best to worst; index is the rank
     * @param  array<string, float>  $points  symbol => points, empty when the
     *                                        qualification has no point scale
     */
    private function __construct(
        public readonly string $type,
        public readonly array $grades,
        public readonly array $points,
    ) {
        $this->rankLookup = [];
        $this->canonicalLookup = [];

        foreach ($grades as $rank => $symbol) {
            $key = self::normalise($symbol);
            $this->rankLookup[$key] = $rank;
            $this->canonicalLookup[$key] = $symbol;
        }
    }

    /**
     * @param  array<int, string>  $grades
     * @param  array<string, float|int>  $points
     */
    public static function make(array $grades, array $points = [], string $type = self::TYPE_GRADE): self
    {
        return new self(
            $type,
            array_values($grades),
            array_map(static fn ($value) => (float) $value, $points),
        );
    }

    /**
     * Rebuild from the JSON stored on the qualification row.
     *
     * A null or unrecognised payload yields an empty scheme rather than an
     * error: a qualification whose grading is not configured must fail to
     * validate a grade, not fail to load.
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self(self::TYPE_GRADE, [], []);
        }

        return new self(
            is_string($raw['type'] ?? null) ? $raw['type'] : self::TYPE_GRADE,
            array_values(array_filter(
                is_array($raw['grades'] ?? null) ? $raw['grades'] : [],
                static fn ($symbol) => is_string($symbol) && $symbol !== '',
            )),
            array_map(
                static fn ($value) => (float) $value,
                array_filter(
                    is_array($raw['points'] ?? null) ? $raw['points'] : [],
                    static fn ($value) => is_numeric($value),
                ),
            ),
        );
    }

    /** @return array{type: string, grades: array<int, string>, points: array<string, float>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'grades' => $this->grades,
            'points' => $this->points,
        ];
    }

    /** Every symbol this qualification recognises, best first. */
    public function symbols(): array
    {
        return $this->grades;
    }

    public function isEmpty(): bool
    {
        return $this->grades === [];
    }

    /** Whether this qualification recognises a symbol at all. */
    public function allows(?string $symbol): bool
    {
        return $this->rank($symbol) !== null;
    }

    /**
     * The symbol as this scheme spells it, so a result is stored in the
     * qualification's own casing (Cambridge AS grades are lower case, and
     * must stay that way) regardless of how it arrived.
     */
    public function canonical(?string $symbol): ?string
    {
        if ($symbol === null) {
            return null;
        }

        return $this->canonicalLookup[self::normalise($symbol)] ?? null;
    }

    /**
     * Position in the ordered grade list, 0 being the best result the
     * qualification awards. Null when the symbol is not part of this scheme -
     * which a caller must treat as "cannot be compared", never as a rank.
     */
    public function rank(?string $symbol): ?int
    {
        if ($symbol === null || $symbol === '') {
            return null;
        }

        return $this->rankLookup[self::normalise($symbol)] ?? null;
    }

    /** Whether this qualification has a point scale at all. */
    public function hasPoints(): bool
    {
        return $this->points !== [];
    }

    /**
     * Points for a symbol, or null when this qualification does not award
     * points. Null here means "this qualification is not counted in points",
     * not "zero points" - O-Level symbols and Cambridge grades must never be
     * added into a total, and returning 0.0 would quietly let them be.
     */
    public function pointsFor(?string $symbol): ?float
    {
        if (! $this->hasPoints() || $symbol === null) {
            return null;
        }

        $canonical = $this->canonical($symbol);

        return $canonical === null ? null : ($this->points[$canonical] ?? null);
    }

    /**
     * Whether a held grade satisfies a required one, both read under this same
     * scheme. Null when either symbol is unknown here, so the caller can say
     * "cannot check" rather than guess - a lexicographic string compare, which
     * is what this replaces, would rank "A*" below "A" and a Cambridge "a"
     * above every upper-case grade.
     */
    public function meets(?string $held, ?string $required): ?bool
    {
        $requiredRank = $this->rank($required);

        if ($requiredRank === null) {
            return null;
        }

        $heldRank = $this->rank($held);

        if ($heldRank === null) {
            return null;
        }

        // Lower index is a better result.
        return $heldRank <= $requiredRank;
    }

    /** The best grade this qualification awards, for deriving ceilings. */
    public function bestSymbol(): ?string
    {
        return $this->grades[0] ?? null;
    }

    /** The highest points a single subject can earn, or 0.0 when unpointed. */
    public function maxSubjectPoints(): float
    {
        return $this->hasPoints() ? (float) max($this->points) : 0.0;
    }

    private static function normalise(string $symbol): string
    {
        return mb_strtoupper(trim($symbol));
    }
}
