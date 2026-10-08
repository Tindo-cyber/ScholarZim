<?php

namespace App\Services\Catalogue;

/** What the old-field migration did (or, on a dry run, would do). */
final class BackfillReport
{
    public int $listingsMigrated = 0;

    public int $rowsCreated = 0;

    /** @var array<int, array{key: string, example: string, listings: int, students: int}> */
    public array $unmapped = [];
}
