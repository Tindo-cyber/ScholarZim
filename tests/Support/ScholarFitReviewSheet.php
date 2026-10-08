<?php

namespace Tests\Support;

/**
 * The answer key as a spreadsheet a person can read in Excel: one row per listing, one column per
 * applicant, E / I / N in each cell, and the reasons beside it. Generated from expected.json,
 * listings.json and applicants.json - never edited by hand (a test fails if it drifts).
 */
final class ScholarFitReviewSheet
{
    private function __construct()
    {
    }

    public static function render(array $applicants, array $listings, array $key): string
    {
        $order = $key['applicant_order'];
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, ['KEY: E = eligible, I = ineligible, N = needs information. Edit expected.json, not this file.'], ',', '"', '');
        fputcsv($handle, [], ',', '"', '');

        fputcsv($handle, array_merge(['Applicant', 'Who'], []), ',', '"', '');

        foreach ($order as $id) {
            $a = $applicants[$id];
            fputcsv($handle, [$id, $a['name'] . ' [' . $a['level'] . ']'], ',', '"', '');
        }

        fputcsv($handle, [], ',', '"', '');
        fputcsv($handle, array_merge(['Listing', 'Title', 'What it asks for'], $order, ['Why (non-obvious cells)']), ',', '"', '');

        foreach ($key['listings'] as $id => $entry) {
            $why = [];

            foreach ($entry['why'] ?? [] as $applicant => $reason) {
                $why[] = $applicant . ': ' . $reason;
            }

            fputcsv($handle, array_merge(
                [$id, $listings[$id]['title'], $listings[$id]['describe']],
                str_split($entry['verdicts']),
                [implode(' | ', $why)]
            ), ',', '"', '');
        }

        fputcsv($handle, [], ',', '"', '');
        fputcsv($handle, ['Order (best first, no score)'], ',', '"', '');

        foreach ($key['order'] as $applicant => $entry) {
            fputcsv($handle, [$applicant, implode(' > ', $entry['expected']), $entry['about']], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
