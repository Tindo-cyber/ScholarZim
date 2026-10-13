<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shipped catalogue files hold together. These read the CSVs directly (no database) and check what the
 * importer cannot: that nothing is listed twice under slightly different wording, that no name doubles as
 * another programme's synonym, and that the big institutions are really covered.
 *
 * CatalogueStarterDataTest already proves every row is one the importer accepts.
 */
class CatalogueIntegrityTest extends TestCase
{
    private const DIR = __DIR__ . '/../../database/seeders/data/';

    /** @return array<int, array{name: string, level: string, field: string, institutions: array<int, string>, synonyms: array<int, string>}> */
    private static function programmes(): array
    {
        $handle = fopen(self::DIR . 'programmes.csv', 'r');
        fgetcsv($handle, 0, ',', '"', '');
        $rows = [];

        while (($r = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $r = array_pad($r, 5, '');
            $rows[] = [
                'name' => $r[0], 'level' => $r[1], 'field' => $r[2],
                'institutions' => array_values(array_filter(array_map('trim', explode(';', $r[3])))),
                'synonyms' => array_values(array_filter(array_map('trim', explode(';', $r[4])))),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /** @return array<int, string> */
    private static function institutionCodes(): array
    {
        $handle = fopen(self::DIR . 'institutions.csv', 'r');
        fgetcsv($handle, 0, ',', '"', '');
        $codes = [];

        while (($r = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $codes[] = $r[0];
        }

        return $codes;
    }

    private static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($text));
    }

    public function test_no_programme_is_listed_twice_at_the_same_level(): void
    {
        $seen = [];

        foreach (self::programmes() as $p) {
            $k = self::key($p['name']) . '|' . $p['level'];
            $this->assertArrayNotHasKey($k, $seen, "'{$p['name']}' ({$p['level']}) is listed twice, as '" . ($seen[$k] ?? '') . "'");
            $seen[$k] = $p['name'];
        }
    }

    public function test_no_name_is_also_another_programmes_synonym_and_no_synonym_has_two_owners(): void
    {
        $names = [];
        $owners = [];

        foreach (self::programmes() as $p) {
            $names[self::key($p['name'])] = $p['name'];
            foreach ($p['synonyms'] as $s) {
                $owners[self::key($s)][$p['name'] . '|' . $p['level']] = true;
            }
        }

        foreach ($owners as $key => $by) {
            $this->assertCount(1, $by, "synonym '$key' belongs to " . implode(' and ', array_keys($by)));
            $this->assertArrayNotHasKey($key, $names, "synonym '$key' is also the name of '" . ($names[$key] ?? '') . "'");
        }
    }

    /** "Bachelor of Laws (LLB)" and "Bachelor of Laws" are one programme: an abbreviation in brackets is not a different name. */
    public function test_a_bracketed_abbreviation_never_makes_a_second_programme(): void
    {
        foreach (self::programmes() as $p) {
            $this->assertDoesNotMatchRegularExpression('/\((?:[A-Z][a-z]?){2,8}\)/', $p['name'], "'{$p['name']}' carries a bracketed abbreviation");
        }
    }

    public function test_every_institution_code_used_exists(): void
    {
        $known = self::institutionCodes();

        foreach (self::programmes() as $p) {
            foreach ($p['institutions'] as $code) {
                $this->assertContains($code, $known, "'{$p['name']}' names an institution that is not in institutions.csv: $code");
            }
            $this->assertSame(array_unique($p['institutions']), $p['institutions'], "'{$p['name']}' lists an institution twice");
        }
    }

    public function test_names_are_in_the_catalogue_style(): void
    {
        foreach (self::programmes() as $p) {
            $name = $p['name'];

            $this->assertSame(trim($name), $name, "'$name' has spaces round it");
            $this->assertStringNotContainsString('  ', $name, "'$name' has a double space");
            $this->assertDoesNotMatchRegularExpression('/\bHonou?rs\b/i', $name, "'$name' still carries the word Honours: it is the Undergraduate level");
            $this->assertLessThanOrEqual(200, strlen($name), "'$name' is too long");
        }
    }

    public function test_the_big_institutions_are_covered(): void
    {
        $count = [];

        foreach (self::programmes() as $p) {
            foreach ($p['institutions'] as $code) {
                $count[$code] = ($count[$code] ?? 0) + 1;
            }
        }

        // The accreditation list names 290 (UZ), 140 (MSU), 129 (NUST), 114 (GZU), 86 (AU) and so on. After merging
        // the same programme under one name the numbers are lower, so these are floors, not equals.
        foreach (['UZ' => 120, 'MSU' => 70, 'NUST' => 70, 'GZU' => 50, 'AU' => 40, 'ZOU' => 30, 'LSU' => 40, 'BUSE' => 40,
            'SOLUSI' => 20, 'WUA' => 15, 'CUZ' => 15, 'CUT' => 15, 'HIT' => 15, 'ZEGU' => 10] as $code => $floor) {
            $this->assertGreaterThanOrEqual($floor, $count[$code] ?? 0, "$code should list at least $floor programmes");
        }
    }

    public function test_the_catalogue_has_all_the_levels_and_some_postgraduate_study(): void
    {
        $levels = array_count_values(array_column(self::programmes(), 'level'));

        foreach (['Undergraduate' => 300, 'Masters' => 100, 'PhD' => 5, 'Diploma' => 30, 'Postgraduate' => 5, 'Certificate' => 8] as $level => $floor) {
            $this->assertGreaterThanOrEqual($floor, $levels[$level] ?? 0, "$level");
        }
    }
}
