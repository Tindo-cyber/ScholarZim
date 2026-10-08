<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScholarFitMarkSheet;

/** Reading a person's filled-in worksheet, and comparing it with the engine's answers. */
class ScholarFitMarkSheetTest extends TestCase
{
    private function sheet(string $body, string $separator = ','): string
    {
        return str_replace(',', $separator, "Fill in each cell\nListing,Title,A1,A2,A3\n" . $body);
    }

    public function test_it_reads_the_cells_by_listing_and_applicant(): void
    {
        $parsed = ScholarFitMarkSheet::parseCsv($this->sheet("L01,Form 1,E,I,N\nL02,O-Level,I,I,E\n"));

        $this->assertSame(['A1' => 'E', 'A2' => 'I', 'A3' => 'N'], $parsed['marks']['L01']);
        $this->assertSame(['A1' => 'I', 'A2' => 'I', 'A3' => 'E'], $parsed['marks']['L02']);
        $this->assertSame(0, $parsed['blank']);
    }

    public function test_it_copes_with_how_a_spreadsheet_saves_a_file(): void
    {
        $body = "L01,Form 1,e,Ineligible,needs information\n";

        foreach ([',', ';', "\t"] as $separator) {
            $parsed = ScholarFitMarkSheet::parseCsv("\xEF\xBB\xBF" . $this->sheet($body, $separator));

            $this->assertSame(['A1' => 'E', 'A2' => 'I', 'A3' => 'N'], $parsed['marks']['L01'], "separator " . json_encode($separator));
        }
    }

    public function test_a_title_containing_a_comma_does_not_shift_the_columns(): void
    {
        $parsed = ScholarFitMarkSheet::parseCsv("Listing,Title,A1,A2\nL01,\"Fees, books and more\",E,I\n");

        $this->assertSame(['A1' => 'E', 'A2' => 'I'], $parsed['marks']['L01']);
    }

    public function test_blank_cells_are_skipped_and_counted(): void
    {
        $parsed = ScholarFitMarkSheet::parseCsv($this->sheet("L01,Form 1,E,,\nL02,O-Level,,,I\n"));

        $this->assertSame(['A1' => 'E'], $parsed['marks']['L01']);
        $this->assertSame(['A3' => 'I'], $parsed['marks']['L02']);
        $this->assertSame(4, $parsed['blank']);
    }

    public function test_a_cell_that_is_not_a_verdict_is_reported_not_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A2 x L01: "maybe" is not E, I or N');

        ScholarFitMarkSheet::parseCsv($this->sheet("L01,Form 1,E,maybe,N\n"));
    }

    public function test_a_file_without_the_applicant_columns_is_explained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Could not find the header row');

        ScholarFitMarkSheet::parseCsv("Listing,Title\nL01,Form 1\n");
    }

    public function test_only_listing_rows_are_read(): void
    {
        $parsed = ScholarFitMarkSheet::parseCsv($this->sheet("L01,Form 1,E,E,E\n\nSome note,here,I,I,I\n"));

        $this->assertSame(['L01'], array_keys($parsed['marks']));
    }

    public function test_it_lists_every_disagreement_and_nothing_else(): void
    {
        $engine = ['L01' => ['A1' => 'E', 'A2' => 'I', 'A3' => 'N'], 'L02' => ['A1' => 'I', 'A2' => 'I', 'A3' => 'E']];
        $mine = ['L01' => ['A1' => 'E', 'A2' => 'E', 'A3' => 'N'], 'L02' => ['A3' => 'I']];

        $this->assertSame([
            ['listing' => 'L01', 'applicant' => 'A2', 'theirs' => 'E', 'engine' => 'I'],
            ['listing' => 'L02', 'applicant' => 'A3', 'theirs' => 'I', 'engine' => 'E'],
        ], ScholarFitMarkSheet::disagreements($mine, $engine));
    }

    public function test_identical_marks_have_no_disagreements(): void
    {
        $same = ['L01' => ['A1' => 'E']];

        $this->assertSame([], ScholarFitMarkSheet::disagreements($same, $same));
    }
}
