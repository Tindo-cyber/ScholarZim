<?php

namespace App\Models;

use App\Services\FileStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document an application was submitted with, as it was at that moment.
 *
 * Every profile document an applicant can upload is recorded: the results certificate, the transcript, the
 * Grade 7 results slip, the CV, the ID or passport and the recommendation letter. The applicant is told that
 * "only you, and a provider you have applied to, can open them", and the provider reviewing the application opens
 * exactly these (FileDownloadController), never whatever the profile holds later. The record points at the stored
 * file rather than copying it, so the file must be kept for as long as any application refers to it - see
 * ApplicantProfileService::storeDocument() and AccountDeletionService.
 *
 * Until the CV, ID and recommendation letter were added, only the first three were recorded, so a provider could
 * never open the others. Applications submitted before that have no record of them and show them as not provided:
 * what was sent cannot be reconstructed from a profile that may have changed since.
 */
class ApplicationDocument extends Model
{
    /** document type => the profile columns that hold it: [path, filename, uploaded at] */
    public const TYPES = [
        'results' => ['results_certificate_path', 'results_certificate_filename', 'results_uploaded_at'],
        'transcript' => ['transcript_path', 'transcript_filename', 'transcript_uploaded_at'],
        'grade7_slip' => ['grade7_slip_path', 'grade7_slip_filename', 'grade7_slip_uploaded_at'],
        'cv' => ['cv_path', 'cv_filename', 'cv_uploaded_at'],
        'passport' => ['passport_path', 'passport_filename', 'passport_uploaded_at'],
        'recommendation' => ['recommendation_letter_path', 'recommendation_letter_filename', 'recommendation_letter_uploaded_at'],
    ];

    /**
     * The types that may be read from the applicant's current profile when an application has no record at all
     * (one older than the records). The three added later never are: an application that predates them must not
     * show a CV or ID the applicant uploaded afterwards.
     */
    public const PROFILE_FALLBACK_TYPES = ['results', 'transcript', 'grade7_slip'];

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

        $storage = app(FileStorageService::class);

        foreach (self::TYPES as $type => [$path, $filename, $uploadedAt]) {
            // A path whose file the store says is gone is not something the application was sent with: recording it
            // would hand the provider a link to nothing. A store that merely could not be asked is not "gone", so
            // that file is still recorded.
            if (filled($profile->{$path}) && $storage->status($profile->{$path}) !== FileStorageService::MISSING) {
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
