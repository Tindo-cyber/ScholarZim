<?php

namespace App\Services;

use App\Models\DocumentFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Uploads land on the private disk, never in public/. Files are served back
 * through FileDownloadController so every read passes an authorisation check.
 */
class FileStorageService
{
    public const DISK = 'local';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** Types browsers can render directly - everything else has no useful inline preview. */
    private const PREVIEWABLE_MIME = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Extension for each accepted type, chosen from the detected MIME rather
     * than from the name the browser sent.
     *
     * The old code took the client's extension, so a file whose contents were a
     * valid PDF could still be stored as "uuid.php" or "uuid.html" simply by
     * being uploaded under that name. Nothing executes it - the disk is outside
     * the web root - but a stored filename that an uploader controls is a loaded
     * gun waiting for the day somebody points a web server at storage/, and the
     * name serves no purpose that the detected type cannot serve better.
     */
    private const EXTENSION_FOR_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public function store(UploadedFile $file, string $folder, ?User $uploader = null): string
    {
        $mime = $this->guard($file);

        $name = Str::uuid()->toString() . '.' . self::EXTENSION_FOR_MIME[$mime];
        $size = (int) $file->getSize();
        $original = $this->safeOriginalName($file);

        $path = $file->storeAs(trim($folder, '/'), $name, self::DISK);

        $this->recordMetadata($path, $name, $original, $mime, $size, $uploader);

        return $path;
    }

    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }

        // The record goes with the bytes. A metadata row outliving its file
        // would describe something that is no longer there, which is worse than
        // no record at all.
        DocumentFile::where('disk', self::DISK)->where('path', $path)->delete();
    }

    /**
     * Everything this user uploaded, removed from disk and from the record.
     *
     * Account deletion used to drop the rows that pointed at these files and
     * leave the files themselves in storage - private documents belonging to an
     * account that no longer exists, with nothing left referring to them and so
     * nothing that would ever find them again.
     *
     * @return int how many files were removed
     */
    public function deleteAllForUser(User $user): int
    {
        $files = DocumentFile::where('uploaded_by_user_id', $user->user_id)->get();

        foreach ($files as $file) {
            if (Storage::disk($file->disk)->exists($file->path)) {
                Storage::disk($file->disk)->delete($file->path);
            }
        }

        DocumentFile::where('uploaded_by_user_id', $user->user_id)->delete();

        return $files->count();
    }

    /** The metadata row for a stored path, when one was recorded. */
    public function metadataFor(?string $path): ?DocumentFile
    {
        if (blank($path)) {
            return null;
        }

        return DocumentFile::where('disk', self::DISK)->where('path', $path)->first();
    }

    /**
     * Whether the bytes on disk still match what was recorded at upload.
     * Cheap enough to run on demand, and the only way to notice silent
     * corruption or a file swapped underneath the application.
     */
    public function checksumMatches(string $path): bool
    {
        $record = $this->metadataFor($path);

        if ($record === null || ! $this->exists($path)) {
            return false;
        }

        return hash_equals($record->checksum, hash_file('sha256', $this->absolutePath($path)));
    }

    public function exists(?string $path): bool
    {
        return filled($path) && Storage::disk(self::DISK)->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function mimeType(string $path): string
    {
        return Storage::disk(self::DISK)->mimeType($path) ?: 'application/octet-stream';
    }

    /**
     * Serves a stored file for the browser to open - inline (viewable in a new
     * tab) for PDF/JPG/PNG, or as a download for anything else, since there is
     * no useful in-browser preview for a Word document.
     */
    public function respond(string $path, ?string $filename = null): BinaryFileResponse
    {
        // A file the scanner flagged is never handed over, whoever is asking and
        // however well they are authorised. Checked here rather than in each
        // controller so a new download endpoint inherits it.
        $record = $this->metadataFor($path);

        if ($record !== null && $record->isQuarantined()) {
            abort(403, 'This file was quarantined and cannot be downloaded.');
        }

        $absolute = $this->absolutePath($path);
        $mime = $this->mimeType($path);
        $name = $filename ?: basename($path);

        $disposition = in_array($mime, self::PREVIEWABLE_MIME, true)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $name,
                str_replace('%', '', Str::ascii($name))
            ),
        ]);
    }

    /**
     * Size and type, checked before anything is written.
     *
     * getMimeType() is finfo over the file's actual contents, not the
     * Content-Type the browser claimed - that header is chosen by whoever is
     * uploading and means nothing. Returned rather than discarded so the caller
     * names the file after what it really is.
     *
     * @return string the detected MIME type
     */
    private function guard(UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new RuntimeException('The upload did not complete. Please try again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('File is larger than the 5 MB limit.');
        }

        $mime = (string) $file->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Only PDF, Word, JPG, and PNG files are accepted.');
        }

        return $mime;
    }

    /**
     * The uploader's own filename, reduced to something safe to echo back.
     *
     * Only ever used as the download filename, never to build a path - but it is
     * still attacker-supplied text that ends up in a Content-Disposition header
     * and on a page, so directory separators, control characters and leading
     * dots come out first.
     */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = (string) $file->getClientOriginalName();

        // basename() first, so "../../etc/passwd" cannot survive as a name at all.
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = ltrim($name, '.');
        $name = trim($name);

        if ($name === '') {
            return 'document';
        }

        return mb_substr($name, 0, 180);
    }

    /**
     * Records what was stored. Failing here must not lose the upload the user
     * just made, so it is logged rather than thrown - the file is on disk and
     * referenced by its owner either way; what is lost is the ability to say how
     * big it was and who sent it.
     */
    private function recordMetadata(
        string $path,
        string $storedName,
        string $originalName,
        string $mime,
        int $size,
        ?User $uploader
    ): void {
        try {
            DocumentFile::create([
                'disk' => self::DISK,
                'path' => $path,
                'original_filename' => $originalName,
                'stored_filename' => $storedName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum' => hash_file('sha256', $this->absolutePath($path)),
                'uploaded_by_user_id' => $uploader?->user_id,
                'scan_status' => DocumentFile::SCAN_PENDING,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not record document metadata', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
