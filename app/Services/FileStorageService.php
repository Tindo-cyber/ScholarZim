<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
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

    public function store(UploadedFile $file, string $folder): string
    {
        $this->guard($file);

        $name = Str::uuid()->toString() . '.' . strtolower($file->getClientOriginalExtension() ?: 'bin');

        return $file->storeAs(trim($folder, '/'), $name, self::DISK);
    }

    public function delete(?string $path): void
    {
        if (filled($path) && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
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

    private function guard(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('File is larger than the 5 MB limit.');
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new RuntimeException('Only PDF, Word, JPG, and PNG files are accepted.');
        }
    }
}
