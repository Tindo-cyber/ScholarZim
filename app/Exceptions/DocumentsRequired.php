<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An application was refused because documents it requires are not on file.
 *
 * A RuntimeException like the other business-rule refusals, so callers that report those keep working; its own type so
 * the apply page can send the applicant to upload exactly these, rather than showing a generic error.
 */
class DocumentsRequired extends RuntimeException
{
    /**
     * @param array<int, string> $labels  the documents still missing, as the applicant knows them
     * @param array<int, string> $lost    the subset that WAS uploaded once but whose file can no longer be found
     */
    public function __construct(public readonly array $labels, public readonly array $lost = [])
    {
        $message = 'Upload ' . self::joinWords($labels) . ' before you apply: ' . (count($labels) === 1 ? 'this scholarship needs it' : 'this scholarship needs them') . '.';

        if ($lost !== []) {
            $message .= ' The file you uploaded earlier for ' . self::joinWords($lost) . ' could not be found, so '
                . (count($lost) === 1 ? 'it needs' : 'they need') . ' uploading again.';
        }

        parent::__construct($message);
    }

    /** @param array<int, string> $items */
    private static function joinWords(array $items): string
    {
        return count($items) <= 1
            ? ($items[0] ?? 'your documents')
            : implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items);
    }
}
