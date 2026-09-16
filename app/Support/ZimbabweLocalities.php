<?php

namespace App\Support;

/**
 * The towns and growth points ScholarZim recognises, and the province each one
 * is in.
 *
 * Locality stays free text. Zimbabwe has far more growth points, business
 * centres and villages than any dropdown would sensibly enumerate, and forcing
 * a choice from a fixed list would lock out exactly the applicants a bursary
 * platform exists for. That decision has not changed.
 *
 * What this adds is a contradiction check. "Midlands" and "Gwanda" cannot both
 * be true - Gwanda is in Matabeleland South - and a profile that claims both
 * will be matched against province-restricted awards on a province it does not
 * actually live in. So a locality this list recognises is checked against the
 * stated province and refused when the two disagree.
 *
 * A locality the list does not recognise is accepted without comment. The list
 * is a set of places we are confident about, not a register of everywhere a
 * Zimbabwean may live, and an unknown name is far more likely to be a small
 * place nobody catalogued than a mistake.
 *
 * @see FormOptions::ZIMBABWE_PROVINCES for the provinces themselves
 */
final class ZimbabweLocalities
{
    /**
     * province => the localities in it.
     *
     * Deliberately limited to places whose province is well established. Adding
     * a name here makes the system willing to contradict an applicant about
     * where they live, which is worth being sure about.
     *
     * @var array<string, array<int, string>>
     */
    private const BY_PROVINCE = [
        'Bulawayo' => [
            'Bulawayo',
        ],
        'Harare' => [
            'Harare', 'Chitungwiza', 'Epworth',
        ],
        'Manicaland' => [
            'Mutare', 'Rusape', 'Chipinge', 'Chimanimani', 'Nyanga', 'Buhera',
            'Headlands', 'Penhalonga', 'Odzi', 'Birchenough Bridge', 'Checheche',
        ],
        'Mashonaland Central' => [
            'Bindura', 'Mount Darwin', 'Shamva', 'Guruve', 'Mvurwi', 'Centenary',
            'Concession', 'Glendale', 'Mazowe', 'Rushinga', 'Muzarabani',
        ],
        'Mashonaland East' => [
            'Marondera', 'Mutoko', 'Murehwa', 'Chivhu', 'Macheke', 'Wedza',
            'Goromonzi', 'Mudzi', 'Ruwa', 'Beatrice', 'Juru',
        ],
        'Mashonaland West' => [
            'Chinhoyi', 'Kariba', 'Kadoma', 'Chegutu', 'Norton', 'Karoi',
            'Banket', 'Mhangura', 'Murombedzi', 'Magunje', 'Sanyati', 'Zvimba',
        ],
        'Masvingo' => [
            'Masvingo', 'Chiredzi', 'Triangle', 'Gutu', 'Bikita', 'Zaka',
            'Mwenezi', 'Chivi', 'Ngundu', 'Mashava', 'Rutenga',
        ],
        'Matabeleland North' => [
            'Hwange', 'Victoria Falls', 'Lupane', 'Binga', 'Nkayi', 'Tsholotsho',
            'Dete', 'Kamativi', 'Jotsholo',
        ],
        'Matabeleland South' => [
            'Gwanda', 'Beitbridge', 'Plumtree', 'Filabusi', 'Esigodini',
            'Maphisa', 'Kezi', 'West Nicholson', 'Colleen Bawn', 'Mangwe',
        ],
        'Midlands' => [
            'Gweru', 'Kwekwe', 'Zvishavane', 'Shurugwi', 'Gokwe', 'Redcliff',
            'Mvuma', 'Mberengwa', 'Lalapanzi', 'Silobela', 'Chirumhanzu',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * The province a locality is in, or null when the name is not one this
     * list recognises.
     *
     * Null means "no opinion", never "wrong".
     */
    public static function provinceFor(?string $locality): ?string
    {
        $needle = self::normalise($locality);

        if ($needle === null) {
            return null;
        }

        foreach (self::BY_PROVINCE as $province => $localities) {
            foreach ($localities as $candidate) {
                if (self::normalise($candidate) === $needle) {
                    return $province;
                }
            }
        }

        return null;
    }

    /**
     * Whether a locality and a province agree.
     *
     * Returns null when either is blank or the locality is unrecognised, which
     * a caller must treat as "cannot say" rather than as a failure - an unknown
     * place name is not a contradiction.
     */
    public static function agreesWithProvince(?string $locality, ?string $province): ?bool
    {
        if (blank($locality) || blank($province)) {
            return null;
        }

        $actual = self::provinceFor($locality);

        return $actual === null ? null : self::normalise($actual) === self::normalise($province);
    }

    /**
     * The sentence for a locality that is in a different province from the one
     * stated, or null when there is no contradiction to report.
     */
    public static function mismatchReason(?string $locality, ?string $province): ?string
    {
        if (self::agreesWithProvince($locality, $province) !== false) {
            return null;
        }

        return trim((string) $locality).' is in '.self::provinceFor($locality)
            .', not '.trim((string) $province).'. Choose the province it is actually in, or clear the town.';
    }

    /** Localities in one province, for an input's suggestion list. */
    public static function forProvince(?string $province): array
    {
        foreach (self::BY_PROVINCE as $name => $localities) {
            if (self::normalise($name) === self::normalise($province)) {
                return $localities;
            }
        }

        return [];
    }

    /** Every recognised locality, for a suggestion list with no province chosen yet. */
    public static function all(): array
    {
        $all = array_merge(...array_values(self::BY_PROVINCE));
        sort($all);

        return $all;
    }

    /** province => localities, for the browser to narrow suggestions as the province changes. */
    public static function map(): array
    {
        return self::BY_PROVINCE;
    }

    private static function normalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = strtolower(trim($value));
        $clean = (string) preg_replace('/\s+/', ' ', $clean);

        return $clean === '' ? null : $clean;
    }
}
