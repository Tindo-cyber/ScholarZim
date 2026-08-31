<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for one stored upload.
 *
 * The row is written by FileStorageService, never by a controller: a file and
 * its record have to appear and disappear together, and the only way to keep
 * that true is for one place to own both.
 */
class DocumentFile extends Model
{
    protected $table = 'document_files';

    protected $primaryKey = 'document_file_id';

    public $timestamps = false;

    /** Nothing has looked at this file yet. */
    public const SCAN_PENDING = 'PENDING';

    /** Scanned, and found nothing. */
    public const SCAN_CLEAN = 'CLEAN';

    /** Scanned, and found something. The file must not be served. */
    public const SCAN_INFECTED = 'INFECTED';

    /**
     * No scanner is configured, so nothing looked. Deliberately distinct from
     * CLEAN - collapsing the two would make an unscanned platform report itself
     * as scanned, which is the one thing a quarantine status must never do.
     */
    public const SCAN_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'disk',
        'path',
        'original_filename',
        'stored_filename',
        'mime_type',
        'size_bytes',
        'checksum',
        'uploaded_by_user_id',
        'scan_status',
        'scanned_at',
        'scan_detail',
        'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'scanned_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }

    /**
     * Whether this file may be handed to somebody.
     *
     * Anything the scanner flagged is withheld. PENDING is allowed through:
     * scanning is asynchronous and optional, and holding every upload hostage to
     * infrastructure that may not exist would break the product rather than
     * harden it. Only a positive detection blocks.
     */
    public function isServeable(): bool
    {
        return $this->scan_status !== self::SCAN_INFECTED;
    }

    public function isQuarantined(): bool
    {
        return $this->scan_status === self::SCAN_INFECTED;
    }
}
