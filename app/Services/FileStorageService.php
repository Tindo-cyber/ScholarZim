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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uploads land on the private disk, never in public/. Files are served back
 * through FileDownloadController so every read passes an authorisation check.
 */
class FileStorageService
{
    /**
     * Retained for backward compatibility with tests and callers that reference
     * the constant directly. The effective disk is resolved at runtime from
     * config('filesystems.default') via diskName(), so production can point at
     * S3/R2 while local and test environments keep using the local driver.
     */
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

    /**
     * The configured filesystem disk, resolved from config at runtime so the
     * application can target S3/R2 in production without hard-coding 's3' in
     * business logic.
     */
    private function diskName(): string
    {
        return (string) config('filesystems.default', self::DISK);
    }

    /** @return \Illuminate\Contracts\Filesystem\Filesystem&object */
    private function disk()
    {
        return Storage::disk($this->diskName());
    }

    public function store(UploadedFile $file, string $folder, ?User $uploader = null): string
    {
        $mime = $this->guard($file);

        $name = Str::uuid()->toString() . '.' . self::EXTENSION_FOR_MIME[$mime];
        $size = (int) $file->getSize();
        $original = $this->safeOriginalName($file);

        $checksum = $this->checksumFromFile($file);

        $path = $file->storeAs(trim($folder, '/'), $name, $this->diskName());

        $this->recordMetadata($path, $name, $original, $mime, $size, $uploader, $checksum);

        return $path;
    }

    public function delete(?string $path): void
    {
        if (blank($path)) {
            return;
        }

        $disk = $this->resolveDiskForPath($path);

        if (Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }

        DocumentFile::where('disk', $disk)->where('path', $path)->delete();
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

        return DocumentFile::where('disk', $this->diskName())
            ->where('path', $path)
            ->first()
            ?? DocumentFile::where('path', $path)->first();
    }

    /**
     * Whether the bytes on disk still match what was recorded at upload.
     * Cheap enough to run on demand, and the only way to notice silent
     * corruption or a file swapped underneath the application.
     *
     * Works with any filesystem driver by streaming the object rather than
     * reading it through a local path, so Cloudflare R2 / S3 objects are
     * supported alongside the local driver.
     */
    public function checksumMatches(string $path): bool
    {
        $record = $this->metadataFor($path);

        if ($record === null || ! $this->exists($path)) {
            return false;
        }

        $stream = Storage::disk($record->disk)->readStream($path);

        if ($stream === false) {
            return false;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $computed = hash_final($hash);
        } finally {
            fclose($stream);
        }

        return hash_equals((string) $record->checksum, $computed);
    }

    public function exists(?string $path): bool
    {
        return filled($path) && $this->disk()->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk($this->diskName())->path($path);
    }

    public function mimeType(string $path): string
    {
        return (string) ($this->disk()->mimeType($path) ?: 'application/octet-stream');
    }

    /**
     * Serves a stored file for the browser to open - inline (viewable in a new
     * tab) for PDF/JPG/PNG, or as a download for anything else, since there is
     * no useful in-browser preview for a Word document.
     *
     * Streams the object directly from the configured disk rather than reading
     * it through a local path, so Cloudflare R2 / S3 objects are supported.
     */
    public function respond(string $path, ?string $filename = null): StreamedResponse
    {
        $record = $this->metadataFor($path);

        if ($record !== null && $record->isQuarantined()) {
            abort(403, 'This file was quarantined and cannot be downloaded.');
        }

        $disk = $record !== null && $record->disk !== ''
            ? Storage::disk($record->disk)
            : $this->disk();

        if (! $disk->exists($path)) {
            abort(404, 'File not found.');
        }

        $mime = $record !== null && $record->mime_type !== ''
            ? $record->mime_type
            : ($disk->mimeType($path) ?: 'application/octet-stream');
        $name = $filename ?: basename($path);

        $disposition = in_array($mime, self::PREVIEWABLE_MIME, true)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        $stream = $disk->readStream($path);

        if ($stream === false) {
            abort(404, 'File not found.');
        }

        $response = new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $disk->size($path),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $name,
                str_replace('%', '', Str::ascii($name))
            ),
        ]);

        return $response;
    }

    /**
     * Resolves which disk a given path lives on.
     *
     * Looks up the DocumentFile record first so that files stored under a
     * different disk (e.g. a legacy 'local' record written before the
     * migration to S3/R2) are deleted from the right place. Falls back to the
     * currently configured disk when no record exists.
     */
    private function resolveDiskForPath(string $path): string
    {
        $record = DocumentFile::where('path', $path)->first();

        return $record !== null && $record->disk !== '' ? $record->disk : $this->diskName();
    }

    /**
     * SHA-256 of the uploaded file, computed from the temporary file PHP
     * provides before it is moved into long-term storage. This avoids relying
     * on Storage::path() — which does not exist for S3/R2 disks.
     */
    private function checksumFromFile(UploadedFile $file): string
    {
        $tempPath = $file->getRealPath();

        if ($tempPath !== false && is_file($tempPath)) {
            return hash_file('sha256', $tempPath);
        }

        $stream = $file->getStream();
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);

        return hash_final($hash);
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
        ?User $uploader,
        string $checksum
    ): void {
        try {
            DocumentFile::create([
                'disk' => $this->diskName(),
                'path' => $path,
                'original_filename' => $originalName,
                'stored_filename' => $storedName,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'checksum' => $checksum,
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
