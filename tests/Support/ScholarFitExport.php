<?php

namespace Tests\Support;

use App\Support\EducationLevel;

/**
 * The accuracy fixtures as spreadsheets, in two folders that are meant to be opened at different times.
 *
 *   1-to-mark/      what a person needs to work the cases out for themselves: the applicants' facts, what each
 *                   listing asks for, and a blank worksheet to fill in. Nothing here says what the answer is.
 *   2-answer-key/   the hand-written answers, and (separately) the reasons for them. Open these after marking,
 *                   so the marking is independent.
 *
 * Generated from applicants.json, listings.json and expected.json; a test fails if the files drift.
 */
final class ScholarFitExport
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, array<string, mixed>>  $applicants
     * @param  array<string, array<string, mixed>>  $listings
     * @param  array<string, mixed>  $key
     * @return array<string, string> path (relative to the export folder) => file contents
     */
    public static function files(array $applicants, array $listings, array $key): array
    {
        $order = $key['applicant_order'];

        return [
            '1-to-mark/applicants.csv' => self::applicantFacts($applicants, $order),
            '1-to-mark/listings.csv' => self::listingFacts($listings, array_keys($key['listings'])),
            '1-to-mark/worksheet.csv' => self::worksheet($listings, $order, array_keys($key['listings'])),
            '2-answer-key/answer-key.csv' => self::answerKey($listings, $order, $key),
            '2-answer-key/reasons.csv' => self::reasons($key),
        ];
    }

    // ------------------------------------------------------------- to mark --

    private static function applicantFacts(array $applicants, array $order): string
    {
        $rows = [['Applicant', 'Who', 'Education level', 'Date of birth', 'Province', 'Town', 'Rural or urban', 'Old field of study (typed before the catalogue)',
            'Programme now', 'Studying at', 'Programmes hoped for', 'Applied to', 'A-Level results', 'Proof of results on file']];

        foreach ($order as $id) {
            $a = $applicants[$id];
            $results = [];

            foreach ($a['results'] ?? [] as $subjects) {
                foreach ($subjects as $subject => $grade) {
                    $results[] = $subject . ' ' . $grade;
                }
            }

            $documents = array_map(fn ($d) => $d === 'results_certificate' ? 'results certificate' : $d, $a['documents'] ?? []);

            $rows[] = [
                $id,
                $a['name'],
                $a['level'] === null ? '(not recorded)' : EducationLevel::label($a['level']),
                $a['dob'] ?? '(not recorded)',
                $a['province'] ?? '(not recorded)',
                $a['locality'] ?? '(not recorded)',
                isset($a['settlement']) ? ucfirst(strtolower($a['settlement'])) : '(not recorded)',
                $a['field_of_study'] ?? '',
                $a['current']['programme'] ?? '',
                $a['current']['institution'] ?? '',
                implode('; ', $a['intended'] ?? []),
                implode('; ', $a['applied'] ?? []),
                implode(', ', $results),
                $documents === [] ? 'none' : implode(', ', $documents),
            ];
        }

        return self::csv($rows);
    }

    private static function listingFacts(array $listings, array $order): string
    {
        $rows = [['Listing', 'Title', 'What it asks for', 'Level it is for', 'Closes']];

        foreach ($order as $id) {
            $l = $listings[$id];
            $rows[] = [$id, $l['title'], $l['describe'], EducationLevel::label($l['level'] ?? null), $l['deadline'] ?? ''];
        }

        return self::csv($rows);
    }

    private static function worksheet(array $listings, array $applicantOrder, array $listingOrder): string
    {
        $rows = [
            ['Fill in each cell with E (eligible), I (ineligible) or N (needs information). Leave a cell empty to skip it. Save as CSV.'],
            array_merge(['Listing', 'Title'], $applicantOrder),
        ];

        foreach ($listingOrder as $id) {
            $rows[] = array_merge([$id, $listings[$id]['title']], array_fill(0, count($applicantOrder), ''));
        }

        return self::csv($rows);
    }

    // ---------------------------------------------------------- answer key --

    private static function answerKey(array $listings, array $applicantOrder, array $key): string
    {
        $rows = [
            ['E = eligible, I = ineligible, N = needs information. Open reasons.csv only after you have marked the worksheet.'],
            array_merge(['Listing', 'Title'], $applicantOrder),
        ];

        foreach ($key['listings'] as $id => $entry) {
            $rows[] = array_merge([$id, $listings[$id]['title']], str_split($entry['verdicts']));
        }

        $rows[] = [];
        $rows[] = ['Order, best first (no score)'];

        foreach ($key['order'] as $applicant => $entry) {
            $rows[] = [$applicant, implode(' > ', $entry['expected'])];
        }

        return self::csv($rows);
    }

    private static function reasons(array $key): string
    {
        $letters = ['E' => 'eligible', 'I' => 'ineligible', 'N' => 'needs information'];
        $rows = [['Listing', 'Applicant', 'Answer in the key', 'Why']];

        foreach ($key['listings'] as $id => $entry) {
            foreach ($entry['why'] ?? [] as $applicant => $reason) {
                $position = array_search($applicant, $key['applicant_order'], true);
                $rows[] = [$id, $applicant, $letters[$entry['verdicts'][$position]], $reason];
            }
        }

        $rows[] = [];
        $rows[] = ['Order', 'Applicant', '', 'Why this order'];

        foreach ($key['order'] as $applicant => $entry) {
            $rows[] = ['Order', $applicant, '', $entry['about']];
        }

        return self::csv($rows);
    }

    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // The byte-order mark makes Excel read it as UTF-8.
        return "\xEF\xBB\xBF" . $csv;
    }
}
