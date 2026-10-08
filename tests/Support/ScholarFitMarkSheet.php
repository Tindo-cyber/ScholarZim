<?php

namespace Tests\Support;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * A person's own verdicts, read from the worksheet they filled in, and the comparison with the engine.
 *
 * Forgiving about how a spreadsheet gets saved (a byte-order mark, a semicolon or tab instead of a comma,
 * lower case, "eligible" written out in full) and strict about what a cell means: anything that is not
 * clearly E, I or N is reported, never guessed at.
 */
final class ScholarFitMarkSheet
{
    private const WORDS = [
        'e' => 'E', 'eligible' => 'E', 'yes' => 'E',
        'i' => 'I', 'ineligible' => 'I', 'not eligible' => 'I', 'no' => 'I',
        'n' => 'N', 'needs information' => 'N', 'needs info' => 'N', 'need information' => 'N',
    ];

    private function __construct()
    {
    }

    /**
     * @return array{marks: array<string, array<string, string>>, blank: int}  listing => applicant => E|I|N
     */
    public static function parseFile(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx') {
            $sheet = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();

            return self::parseRows($sheet->toArray(null, false, false, false));
        }

        return self::parseCsv((string) file_get_contents($path));
    }

    /** @return array{marks: array<string, array<string, string>>, blank: int} */
    public static function parseCsv(string $csv): array
    {
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        // The delimiter is whatever the header row (the one naming A1, A2, ...) uses; a note above it may have none.
        $first = collect(explode("\n", $csv))->first(fn ($line) => preg_match('/\bA1\b/i', $line)) ?? (strtok($csv, "\n") ?: '');
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return self::parseRows($rows);
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{marks: array<string, array<string, string>>, blank: int}
     */
    public static function parseRows(array $rows): array
    {
        $header = null;
        $columns = [];

        foreach ($rows as $index => $row) {
            $found = [];

            foreach ($row as $column => $cell) {
                if (preg_match('/^A\d+$/i', trim((string) $cell))) {
                    $found[strtoupper(trim((string) $cell))] = $column;
                }
            }

            if (count($found) >= 2) {
                $header = $index;
                $columns = $found;

                break;
            }
        }

        if ($header === null) {
            throw new InvalidArgumentException('Could not find the header row (a row with the applicant columns A1, A2, ...). Did you save the worksheet as CSV?');
        }

        $marks = [];
        $blank = 0;
        $problems = [];

        foreach (array_slice($rows, $header + 1) as $row) {
            $listing = strtoupper(trim((string) ($row[0] ?? '')));

            if (! preg_match('/^L\d+$/', $listing)) {
                continue;
            }

            foreach ($columns as $applicant => $column) {
                $cell = strtolower(trim((string) ($row[$column] ?? '')));

                if ($cell === '') {
                    $blank++;

                    continue;
                }

                if (! isset(self::WORDS[$cell])) {
                    $problems[] = "$applicant x $listing: \"$cell\" is not E, I or N";

                    continue;
                }

                $marks[$listing][$applicant] = self::WORDS[$cell];
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException("Some cells could not be read:\n  " . implode("\n  ", $problems));
        }

        return ['marks' => $marks, 'blank' => $blank];
    }

    /**
     * Where the person's verdict differs from the engine's.
     *
     * @param  array<string, array<string, string>>  $theirs  listing => applicant => letter
     * @param  array<string, array<string, string>>  $engine  listing => applicant => letter
     * @return array<int, array{listing: string, applicant: string, theirs: string, engine: string}>
     */
    public static function disagreements(array $theirs, array $engine): array
    {
        $out = [];

        foreach ($theirs as $listing => $cells) {
            foreach ($cells as $applicant => $letter) {
                $actual = $engine[$listing][$applicant] ?? null;

                if ($actual !== null && $actual !== $letter) {
                    $out[] = ['listing' => $listing, 'applicant' => $applicant, 'theirs' => $letter, 'engine' => $actual];
                }
            }
        }

        return $out;
    }
}
