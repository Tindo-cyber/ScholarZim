<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MailgunApiService;
use Illuminate\Http\JsonResponse;

/**
 * `php artisan mail:check`, reachable from a browser.
 *
 * The command is the better tool, but it needs a shell, and Render does not
 * give one on the free plan - which is exactly the deployment where mail broke
 * and stayed broken, because the only way to see why was a log line nobody was
 * reading. A diagnostic that cannot be run on the instance that is failing is
 * not much of a diagnostic.
 *
 * Administrator-only, behind the same auth and role middleware as the rest of
 * the admin area. It is deliberately not part of /health: that endpoint is
 * unauthenticated and polled constantly, and neither credential details nor a
 * third-party round trip belong there.
 *
 * The credential is never returned. What comes back is a fingerprint - length,
 * and the first twelve hex characters of its SHA-256 - which is enough to
 * answer "is this the same key that works elsewhere?" and useless for anything
 * else. Length is the field that earns its place: a value pasted into a
 * dashboard with a trailing newline or wrapping quotes looks identical to the
 * eye and fails with a flat 401, and only the length gives it away.
 */
class MailDiagnosticsController extends Controller
{
    public function __invoke(MailgunApiService $mailgun): JsonResponse
    {
        $configuration = [
            'mailer' => config('mail.default'),
            'delivery_path' => config('mail.default') === 'mailgun'
                ? 'Mailgun HTTP API (no SMTP)'
                : 'Laravel "' . config('mail.default') . '" transport',
            'queue_connection' => config('queue.default'),
            'from_address' => config('mail.from.address'),
            'mailgun_domain' => $mailgun->domain(),
            'mailgun_endpoint' => $mailgun->endpoint(),
            'credential' => $mailgun->credentialFingerprint(),
        ];

        if (config('mail.default') !== 'mailgun' || ! $mailgun->isConfigured()) {
            return response()->json([
                'stage_1_configuration' => $configuration,
                'stage_2_mailgun' => ['checked' => false, 'reason' => 'Mailgun API not configured or not selected'],
                'stage_3_submission' => 'not attempted from this endpoint',
                'stage_4_delivery' => 'never verifiable from here - see Mailgun logs',
            ], 200);
        }

        // Read-only. This endpoint never sends a message: a GET request that
        // could email somebody is a GET request somebody will eventually
        // trigger by refreshing.
        $result = $mailgun->fetchDomain();

        $payload = $result->payload ?? [];

        return response()->json([
            'stage_1_configuration' => $configuration,
            'stage_2_mailgun' => [
                'checked' => true,
                'url' => $mailgun->domainUrl(),
                'ok' => $result->success,
                'http_status' => $result->status,
                'reason' => $result->reason,
                'error' => $result->error,
                'domain_state' => $payload['domain']['state'] ?? null,
                'sending_dns' => collect($payload['sending_dns_records'] ?? [])
                    ->map(fn ($r) => ['type' => $r['record_type'] ?? '?', 'name' => $r['name'] ?? '', 'valid' => $r['valid'] ?? 'unknown'])
                    ->all(),
            ],
            'stage_3_submission' => 'not attempted - run `php artisan mail:check --send=you@example.com`',
            'stage_4_delivery' => 'never verifiable from here - Mailgun accepting a message is not delivery',
        ], $result->success ? 200 : 503);
    }
}
