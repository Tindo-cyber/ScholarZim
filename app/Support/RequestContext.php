<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The correlation id for whatever is currently being handled, and the request
 * details worth recording alongside it.
 *
 * Held in a static rather than on the request object because the things that
 * need it are not all handling a request: a queued job has no Request, and a
 * scheduled command has neither. All three write audit entries and log lines,
 * and all three need to say which piece of work they belong to.
 *
 * The id is a plain random string rather than anything derived from the user or
 * the URL - it identifies a unit of work and must not itself become a way to
 * learn who did it.
 */
final class RequestContext
{
    /** The header a load balancer or proxy may already have set. */
    public const HEADER = 'X-Request-Id';

    private static ?string $requestId = null;

    private static ?string $ipAddress = null;

    private static ?string $userAgent = null;

    private function __construct()
    {
    }

    /**
     * The current id, generating one on first use.
     *
     * Generating lazily means a console command or a test that never went
     * through the middleware still gets a usable id rather than a null that has
     * to be handled at every call site.
     */
    public static function id(): string
    {
        return self::$requestId ??= (string) Str::uuid();
    }

    /**
     * Adopts an inbound id, or starts a new one.
     *
     * An id supplied by a caller is only trusted as far as its shape: it ends up
     * in log files and in the audit trail, so anything long or strange enough to
     * be an injection attempt is replaced rather than stored. Accepting one at
     * all is what lets a trace span the proxy in front of the application.
     */
    public static function adopt(?string $incoming): string
    {
        $clean = is_string($incoming) ? trim($incoming) : '';

        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $clean) === 1
            ? $clean
            : (string) Str::uuid();

        return self::$requestId;
    }

    public static function setClient(?string $ipAddress, ?string $userAgent): void
    {
        self::$ipAddress = $ipAddress;
        // Truncated to the column width here rather than at each writer.
        self::$userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
    }

    public static function ipAddress(): ?string
    {
        return self::$ipAddress;
    }

    public static function userAgent(): ?string
    {
        return self::$userAgent;
    }

    /** @return array<string, mixed> the fields worth attaching to every log line */
    public static function forLogging(): array
    {
        return array_filter([
            'request_id' => self::id(),
            'ip' => self::$ipAddress,
        ], static fn ($v) => $v !== null);
    }

    /** Between queued jobs in one worker process, so ids do not bleed across. */
    public static function reset(): void
    {
        self::$requestId = null;
        self::$ipAddress = null;
        self::$userAgent = null;
    }
}
