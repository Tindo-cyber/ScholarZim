<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The documents an applicant is asked for, in one list, and where each stands.
 *
 * This is the single answer to "which documents, how many are required, how many are uploaded". The profile's
 * upload card, the apply wizard, the submission check, the profile-completion checklist and the provider's review
 * page all read it (through ApplicantProfile::documentChecklist()), so a count shown in one place cannot differ from
 * the one enforced in another. Before it, the profile page counted the level's required documents while drawing a
 * control for a fourth (the optional CV), the wizard recomputed the list with a scholarship in mind, and neither said
 * which documents were extra.
 *
 * Each item is ['type', 'label', 'required', 'reason', 'group', 'present', 'filename', 'uploaded_at']:
 *
 *   required   whether the application cannot go ahead without it. Optional items are listed but never counted.
 *   reason     why it is required: 'level' (the applicant's education level asks for it) or 'scholarship' (this
 *              scholarship asks for it, whatever the level); null for an optional document.
 *   group      'academic' for certificates, 'other' for the rest.
 *   state      where it stands: AVAILABLE (the file is on its disk), NOT_UPLOADED (nothing was ever stored),
 *              FILE_MISSING (a path is recorded but the disk has no such file) or STORAGE_ERROR (the disk could not
 *              be asked, so nothing is known either way).
 *   present    the file is available. A recorded path is NOT enough: only a file that can actually be opened counts,
 *              so an upload that is gone from storage cannot satisfy a requirement. A document counts once however
 *              many times it was uploaded: the profile holds one file per type and an upload replaces it.
 *
 * The rules themselves (which type is required at which level) live in ApplicantProfile::documentRequirementsFor();
 * this class only holds and counts what they produced.
 */
final class DocumentChecklist
{
    /** Types whose upload is an academic certificate rather than another supporting document. */
    public const ACADEMIC_TYPES = ['results', 'transcript', 'grade7_slip'];

    public const AVAILABLE = 'available';

    public const NOT_UPLOADED = 'not_uploaded';

    public const FILE_MISSING = 'file_missing';

    public const STORAGE_ERROR = 'storage_error';

    /**
     * @param  array<int, array{type: string, label: string, required: bool, reason: ?string, group: string, present: bool, filename: ?string, uploaded_at: ?Carbon}>  $items
     */
    public function __construct(public readonly array $items)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function required(): array
    {
        return array_values(array_filter($this->items, static fn (array $item) => $item['required']));
    }

    /** @return array<int, array<string, mixed>> */
    public function optional(): array
    {
        return array_values(array_filter($this->items, static fn (array $item) => ! $item['required']));
    }

    /**
     * The required documents that are definitely not there: never uploaded, or uploaded and since gone from storage.
     * A document whose storage could not be checked is NOT here - see unverified() - because the applicant is not to
     * be told to upload again something that may be perfectly fine.
     *
     * @return array<int, array<string, mixed>>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->required(),
            fn (array $item) => ! $item['present'] && self::stateOf($item) !== self::STORAGE_ERROR
        ));
    }

    /** @return array<int, array<string, mixed>> required documents whose storage could not be checked just now */
    public function unverified(): array
    {
        return array_values(array_filter($this->required(), fn (array $item) => self::stateOf($item) === self::STORAGE_ERROR));
    }

    /** An item built without a state (a synthetic one) is judged by whether it is present. */
    private static function stateOf(array $item): string
    {
        return $item['state'] ?? ($item['present'] ? self::AVAILABLE : self::NOT_UPLOADED);
    }

    /** True when storage could not be asked about at least one required document. */
    public function storageUnavailable(): bool
    {
        return $this->unverified() !== [];
    }

    /** @return array<int, string> */
    public function requiredTypes(): array
    {
        return array_column($this->required(), 'type');
    }

    /** @return array<int, string> */
    public function missingTypes(): array
    {
        return array_column($this->missing(), 'type');
    }

    public function requiredCount(): int
    {
        return count($this->required());
    }

    public function missingCount(): int
    {
        return count($this->missing());
    }

    /** Required documents that are on file. Never counts an optional one, or one that could not be confirmed. */
    public function uploadedCount(): int
    {
        return $this->requiredCount() - $this->missingCount() - count($this->unverified());
    }

    /** True only when every required document is confirmed available - and, with nothing required, trivially true. */
    public function isComplete(): bool
    {
        return $this->missingCount() === 0 && ! $this->storageUnavailable();
    }

    /** @return array<int, array<string, mixed>> the items in one group, in order */
    public function group(string $group): array
    {
        return array_values(array_filter($this->items, static fn (array $item) => $item['group'] === $group));
    }

    /** @return array<string, mixed>|null */
    public function item(string $type): ?array
    {
        foreach ($this->items as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }

        return null;
    }

    /** "3 documents are required for your education level." - null when nothing is required. */
    public function requiredSentence(string $context = 'for your education level'): ?string
    {
        $count = $this->requiredCount();

        if ($count === 0) {
            return null;
        }

        return $count . ' ' . Str::plural('document', $count) . ($count === 1 ? ' is' : ' are') . ' required ' . $context . '.';
    }

    /** "1 of 3 required documents uploaded - 2 documents remaining." or "All required documents uploaded." */
    public function progressSentence(): ?string
    {
        if ($this->requiredCount() === 0) {
            return null;
        }

        if ($this->isComplete()) {
            return 'All required documents uploaded.';
        }

        if ($this->missingCount() === 0) {
            return 'Your documents could not be checked just now. Please try again shortly.';
        }

        $missing = $this->missingCount();

        return $this->uploadedCount() . ' of ' . $this->requiredCount() . ' required documents uploaded - '
            . $missing . ' ' . Str::plural('document', $missing) . ' remaining.';
    }
}
