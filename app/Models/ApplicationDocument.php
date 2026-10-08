<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document an application was submitted with, as it was at that moment.
 *
 * Only the three a reviewing provider can open are recorded: the results certificate, the transcript and
 * the Grade 7 results slip. The record points at the stored file rather than copying it, so the file must
 * be kept for as long as any application refers to it - see ApplicantProfileService::storeDocument() and
 * AccountDeletionService.
 */
class ApplicationDocument extends Model
{
    /** document type => the profile columns that hold it: [path, filename, uploaded at] */
    public const TYPES = [
        'results' => ['results_certificate_path', 'results_certificate_filename', 'results_uploaded_at'],
        'transcript' => ['transcript_path', 'transcript_filename', 'transcript_uploaded_at'],
        'grade7_slip' => ['grade7_slip_path', 'grade7_slip_filename', 'grade7_slip_uploaded_at'],
    ];

    public $timestamps = false;

    protected $table = 'application_documents';

    protected $fillable = ['application_id', 'type', 'path', 'filename', 'uploaded_at', 'recorded_at'];

    protected $casts = ['uploaded_at' => 'datetime', 'recorded_at' => 'datetime'];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id', 'application_id');
    }

    /** Record the documents an applicant has right now against an application, replacing any earlier record. */
    public static function record(Application $application, ApplicantProfile $profile): void
    {
        static::where('application_id', $application->application_id)->delete();

        foreach (self::TYPES as $type => [$path, $filename, $uploadedAt]) {
            if (filled($profile->{$path})) {
                static::create([
                    'application_id' => $application->application_id,
                    'type' => $type,
                    'path' => $profile->{$path},
                    'filename' => $profile->{$filename},
                    'uploaded_at' => $profile->{$uploadedAt},
                    'recorded_at' => now(),
                ]);
            }
        }
    }

    /** Whether any application still refers to this stored file. */
    public static function isReferenced(?string $path): bool
    {
        return filled($path) && static::where('path', $path)->exists();
    }
}
