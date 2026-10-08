<?php

namespace App\Services\Catalogue;

/** What an import did: counts, the rows it refused and why, and a problem with the file as a whole. */
final class ImportReport
{
    public int $created = 0;

    public int $updated = 0;

    /** @var array<int, array{row: int, reason: string, data: array<string, string>}> row 1 is the first data row */
    public array $rejected = [];

    /** Set when nothing could be imported at all (wrong file type, missing columns, too many rows). */
    public ?string $fileProblem = null;

    public function reject(int $row, string $reason, array $data = []): void
    {
        $this->rejected[] = ['row' => $row, 'reason' => $reason, 'data' => $data];
    }

    public static function failed(string $problem): self
    {
        $report = new self();
        $report->fileProblem = $problem;

        return $report;
    }

    public function ok(): bool
    {
        return $this->fileProblem === null && $this->rejected === [];
    }

    public function summary(): string
    {
        if ($this->fileProblem !== null) {
            return 'Nothing was imported: ' . $this->fileProblem;
        }

        $text = $this->created . ' added, ' . $this->updated . ' updated';

        return $this->rejected === [] ? $text . '.' : $text . ', ' . count($this->rejected) . ' row(s) refused.';
    }
}
