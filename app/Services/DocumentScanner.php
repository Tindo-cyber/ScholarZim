<?php

namespace App\Services;

use App\Models\DocumentFile;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Optional malware scanning, built so that having no scanner is a supported
 * state rather than a broken one.
 *
 * ScholarZim runs on a single VPS with no antivirus daemon, and requiring one
 * would mean either shipping a platform that refuses every upload or shipping
 * one that pretends to scan. Neither is honest, so this does a third thing: when
 * no engine is configured it marks files SKIPPED and says so, and the quarantine
 * machinery around it - the status column, the refusal to serve an infected
 * file, the audit entry - is real and tested either way. Wiring in ClamAV later
 * is a config change and an implementation of scanFile(), not a redesign.
 *
 * The deliberate choice is that PENDING and SKIPPED still serve. Holding every
 * document hostage to infrastructure that may not exist would break applications
 * for students who have done nothing wrong; only a positive detection withholds
 * a file. That is a weaker guarantee than a blocking scanner and is stated
 * plainly rather than dressed up.
 */
class DocumentScanner
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function isEnabled(): bool
    {
        return (bool) config('scholarzim.antivirus.enabled', false);
    }

    /**
     * Scans one recorded file and stores the verdict.
     *
     * Safe to call repeatedly: a file already marked INFECTED is not re-scanned
     * back to clean by a scanner that has since been switched off.
     */
    public function scan(DocumentFile $file): string
    {
        if ($file->scan_status === DocumentFile::SCAN_INFECTED) {
            return DocumentFile::SCAN_INFECTED;
        }

        if (! $this->isEnabled()) {
            return $this->record($file, DocumentFile::SCAN_SKIPPED, 'No scanner configured');
        }

        if (! Storage::disk($file->disk)->exists($file->path)) {
            return $this->record($file, DocumentFile::SCAN_SKIPPED, 'File no longer on disk');
        }

        try {
            [$status, $detail] = $this->scanFile(Storage::disk($file->disk)->path($file->path));
        } catch (\Throwable $e) {
            // A scanner that is down must not silently pass files as clean, and
            // must not delete them either. Left PENDING so a later run picks it
            // up, and recorded so the outage is visible.
            Log::warning('Document scan failed', ['path' => $file->path, 'error' => $e->getMessage()]);

            return $this->record($file, DocumentFile::SCAN_PENDING, 'Scanner unavailable: ' . $e->getMessage());
        }

        if ($status === DocumentFile::SCAN_INFECTED) {
            $this->auditService->log(
                $file->uploader?->email ?? 'unknown',
                AuditAction::DOCUMENT_QUARANTINED,
                'DOCUMENT_FILE',
                $file->document_file_id,
                'Quarantined ' . $file->original_filename . ': ' . $detail
            );
        }

        return $this->record($file, $status, $detail);
    }

    /**
     * Everything nothing has looked at yet, oldest first.
     *
     * @return int how many were scanned
     */
    public function scanPending(int $limit = 100): int
    {
        $pending = DocumentFile::where('scan_status', DocumentFile::SCAN_PENDING)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($pending as $file) {
            $this->scan($file);
        }

        return $pending->count();
    }

    /**
     * The engine itself. The single seam a real scanner plugs into.
     *
     * A ClamAV implementation would run clamdscan here and read its exit code;
     * with none configured this is never reached, because scan() returns SKIPPED
     * before calling it.
     *
     * @return array{0: string, 1: string} status and detail
     */
    protected function scanFile(string $absolutePath): array
    {
        $command = (string) config('scholarzim.antivirus.command', '');

        if ($command === '') {
            return [DocumentFile::SCAN_SKIPPED, 'No scanner command configured'];
        }

        $process = proc_open(
            $command . ' ' . escapeshellarg($absolutePath),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Could not start the scanner process');
        }

        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // clamdscan's convention, which most scanners follow: 0 clean, 1 found
        // something, anything else is the scanner itself failing.
        return match ($exitCode) {
            0 => [DocumentFile::SCAN_CLEAN, 'No threats found'],
            1 => [DocumentFile::SCAN_INFECTED, $output !== '' ? mb_substr($output, 0, 200) : 'Threat detected'],
            default => throw new \RuntimeException('Scanner exited with code ' . $exitCode),
        };
    }

    private function record(DocumentFile $file, string $status, string $detail): string
    {
        $file->update([
            'scan_status' => $status,
            'scanned_at' => Carbon::now(),
            'scan_detail' => mb_substr($detail, 0, 255),
        ]);

        return $status;
    }
}
