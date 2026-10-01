<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin\MailDiagnosticsController's counterpart for the document disk.
 *
 * Same reason to exist: the real tool is `php artisan tinker`, but this
 * Render plan has no Shell tab, so a question as simple as "what is this
 * provider's certificate_path, and does the disk actually have it" has no
 * way to be answered without a code path that answers it from a browser.
 *
 * Administrator-only, behind the same auth and role middleware as the rest
 * of the admin area.
 *
 * providerCertificate() is read-only. writeTest() is not - it performs a
 * real put()/exists()/delete() round trip against the configured disk,
 * under a "diagnostics/" prefix that no application code reads from or
 * writes to, so it can answer "does a write actually succeed with the
 * credentials configured right now" directly, rather than inferring it from
 * a real registration attempt and a log pipeline that may itself be
 * misconfigured.
 *
 * No credential is ever returned, the same guarantee MailDiagnosticsController
 * makes for the Mailgun key: what comes back for AWS_ACCESS_KEY_ID and
 * AWS_SECRET_ACCESS_KEY is a fingerprint (presence, length, first twelve hex
 * characters of a SHA-256), enough to tell "configured" from "blank" and
 * "the same value as before" from "silently changed", and useless for
 * anything else. The bucket, region and endpoint are not credentials and are
 * returned as configured.
 *
 * providerCertificate() is deliberately scoped to one provider's certificate
 * rather than any disk path generically - that is the one question actually
 * in front of us, and a path taken from the URL and handed to the storage
 * layer is exactly the kind of surface this project does not add without a
 * reason.
 */
class StorageDiagnosticsController extends Controller
{
    public function providerCertificate(int $userId, FileStorageService $fileStorage): JsonResponse
    {
        $diskName = (string) config('filesystems.default');
        $diskConfig = (array) config("filesystems.disks.{$diskName}", []);

        $configuration = [
            'disk' => $diskName,
            'driver' => $diskConfig['driver'] ?? null,
            'bucket' => $diskConfig['bucket'] ?? null,
            'region' => $diskConfig['region'] ?? null,
            'endpoint' => $diskConfig['endpoint'] ?? null,
            'use_path_style_endpoint' => $diskConfig['use_path_style_endpoint'] ?? null,
            'key' => $this->fingerprint((string) ($diskConfig['key'] ?? '')),
            'secret' => $this->fingerprint((string) ($diskConfig['secret'] ?? '')),
        ];

        $profile = ProviderProfile::where('user_id', $userId)->first();

        if ($profile === null) {
            return response()->json([
                'stage_1_configuration' => $configuration,
                'stage_2_provider_profile' => ['found' => false],
                'stage_3_existence_check' => ['attempted' => false, 'reason' => 'no ProviderProfile for this user_id'],
            ], 404);
        }

        $providerProfile = [
            'found' => true,
            'certificate_path' => $profile->certificate_path,
            'certificate_path_blank' => blank($profile->certificate_path),
            'certificate_filename' => $profile->certificate_filename,
            'submitted_at' => optional($profile->submitted_at)->toDateTimeString(),
        ];

        if (blank($profile->certificate_path)) {
            return response()->json([
                'stage_1_configuration' => $configuration,
                'stage_2_provider_profile' => $providerProfile,
                'stage_3_existence_check' => ['attempted' => false, 'reason' => 'certificate_path is blank - nothing to check'],
            ], 200);
        }

        // Storage::disk()->exists() directly, not FileStorageService::exists():
        // that method's own try/catch would report "false" here the same as a
        // file that is genuinely missing. This diagnostic needs to see the raw
        // exception - if there is one - not have it already turned into false.
        try {
            $exists = Storage::disk($diskName)->exists($profile->certificate_path);

            $existenceCheck = [
                'attempted' => true,
                'exists' => $exists,
                'exception_chain' => null,
            ];
        } catch (\Throwable $e) {
            $existenceCheck = [
                'attempted' => true,
                'exists' => null,
                'exception_chain' => $fileStorage->exceptionChain($e),
            ];
        }

        return response()->json([
            'stage_1_configuration' => $configuration,
            'stage_2_provider_profile' => $providerProfile,
            'stage_3_existence_check' => $existenceCheck,
        ], 200);
    }

    /**
     * A real put() -> exists() -> delete() round trip against the configured
     * disk, under a path no other code ever touches.
     *
     * put() carries the identical risk store()'s storeAs() does: with
     * 'throw' => false (every disk in config/filesystems.php), a refused
     * write returns false rather than throwing. This checks both - a thrown
     * exception and a clean false - at every stage, so whichever way the
     * current credentials fail, the real error surfaces here.
     */
    public function writeTest(FileStorageService $fileStorage): JsonResponse
    {
        $diskName = (string) config('filesystems.default');
        $path = 'diagnostics/write-test-' . now()->format('YmdHis') . '-' . Str::random(8) . '.txt';
        $contents = 'ScholarZim storage diagnostic write test - ' . now()->toIso8601String();

        $result = ['disk' => $diskName, 'path' => $path];

        try {
            $put = Storage::disk($diskName)->put($path, $contents);
        } catch (\Throwable $e) {
            return response()->json($result + [
                'stage' => 'put',
                'succeeded' => false,
                'exception_chain' => $fileStorage->exceptionChain($e),
            ], 503);
        }

        if ($put === false) {
            return response()->json($result + [
                'stage' => 'put',
                'succeeded' => false,
                'reason' => 'put() returned false without throwing - the write was refused by the disk.',
            ], 503);
        }

        try {
            $exists = Storage::disk($diskName)->exists($path);
        } catch (\Throwable $e) {
            return response()->json($result + [
                'stage' => 'exists',
                'succeeded' => false,
                'exception_chain' => $fileStorage->exceptionChain($e),
            ], 503);
        }

        $cleanupExceptionChain = null;

        try {
            Storage::disk($diskName)->delete($path);
        } catch (\Throwable $e) {
            // The round trip itself already answered the real question by
            // this point - a failure to clean up afterward is noted, not
            // treated as the test having failed.
            $cleanupExceptionChain = $fileStorage->exceptionChain($e);
        }

        return response()->json($result + [
            'stage' => 'complete',
            'succeeded' => $exists === true,
            'exists_after_write' => $exists,
            'cleanup_exception_chain' => $cleanupExceptionChain,
        ], $exists === true ? 200 : 503);
    }

    /** @return array{configured: bool, length: int, sha256_prefix: ?string} */
    private function fingerprint(string $value): array
    {
        return [
            'configured' => $value !== '',
            'length' => strlen($value),
            'sha256_prefix' => $value === '' ? null : substr(hash('sha256', $value), 0, 12),
        ];
    }
}
