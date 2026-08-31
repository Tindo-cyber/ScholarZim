<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\RequestContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Append-only trail, best effort. Audit failures must never break the action
     * being audited, so problems are logged and swallowed.
     *
     * The trade is that a successful action can end up with no line describing
     * it. Where that is not acceptable - anything inside a transaction, where
     * the write can simply be rolled back with everything else - use
     * logOrFail() instead.
     */
    public function log(
        string $actorEmail,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $details = null,
        array $context = []
    ): void {
        try {
            $this->logOrFail($actorEmail, $action, $entityType, $entityId, $details, $context);
        } catch (\Throwable $e) {
            Log::warning('Audit write failed', [
                'action' => $action,
                'actor' => $actorEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The same write, with the failure left to the caller.
     *
     * Called from inside a transaction this makes the trail exact in both
     * directions: no audit line survives an operation that rolled back, and no
     * operation commits without its line. Swallowing here would quietly break
     * the second half - the row would be saved, the record of who changed it
     * lost, and nothing would say so.
     */
    /**
     * @param  array{old?: array<string,mixed>, new?: array<string,mixed>, reason?: ?string}  $context
     *   what the record looked like before and after, and why it changed
     */
    public function logOrFail(
        string $actorEmail,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $details = null,
        array $context = []
    ): void {
        AuditLog::create([
            'actor_email' => $actorEmail,
            'actor_user_id' => $this->resolveActorId($actorEmail),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'old_values' => $this->scrub($context['old'] ?? null),
            'new_values' => $this->scrub($context['new'] ?? null),
            'reason' => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 500) : null,
            // Taken from the ambient request rather than passed in by 46 call
            // sites, none of which have a reason to know about IP addresses.
            'ip_address' => RequestContext::ipAddress(),
            'user_agent' => RequestContext::userAgent(),
            'request_id' => RequestContext::id(),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Only the columns that actually changed, before and after.
     *
     * Saves callers from diffing by hand and keeps entries small: recording a
     * whole row on every edit buries the one field that moved.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array{old: array<string,mixed>, new: array<string,mixed>}
     */
    public function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            // Loose comparison on purpose: form input arrives as strings, and
            // "12" replacing 12 is not a change anybody made.
            if ((string) ($previous ?? '') !== (string) ($value ?? '')) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return ['old' => $old, 'new' => $new];
    }

    /**
     * Keys whose values must never reach the audit table.
     *
     * Matched as substrings, so `password_hash`, `current_password` and
     * `two_factor_secret` are all caught by their stem. An audit trail is read by
     * more people than the database is, kept longer, and exported to explain
     * decisions - it is the last place a credential should end up, and the check
     * lives here rather than at the call sites so that adding one cannot leak by
     * omission.
     */
    private const REDACTED_KEYS = [
        'password', 'token', 'secret', 'recovery', 'two_factor',
        'api_key', 'authorization', 'session', 'csrf', 'remember',
    ];

    /**
     * @param  array<string,mixed>|null  $values
     * @return array<string,mixed>|null
     */
    private function scrub(?array $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        $clean = [];

        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);
            $sensitive = false;

            foreach (self::REDACTED_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $sensitive = true;
                    break;
                }
            }

            if ($sensitive) {
                $clean[$key] = '[redacted]';

                continue;
            }

            // Nested arrays are scrubbed too; anything else is stringified so a
            // model or a date lands as something readable rather than as an
            // object the JSON cast cannot describe.
            $clean[$key] = match (true) {
                is_array($value) => $this->scrub($value),
                is_scalar($value), $value === null => $value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                default => (string) $value,
            };
        }

        return $clean;
    }

    /**
     * The signed-in user's id, when the entry is about their own action.
     *
     * Compared by email rather than assumed, because an administrator acting on
     * somebody else's account passes that person's address as the actor in some
     * paths - attributing the entry to the admin's id would be wrong. No lookup
     * is done for a non-matching address: the email is already the durable
     * identity, and a query per audit write is a poor trade for a convenience
     * column.
     */
    private function resolveActorId(string $actorEmail): ?int
    {
        $user = auth()->user();

        return $user !== null && strcasecmp((string) $user->email, $actorEmail) === 0
            ? $user->user_id
            : null;
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    public function paginate(array $filters = [], int $perPage = 25)
    {
        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('audit_id');

        if (filled($filters['action'] ?? null)) {
            $query->where('action', $filters['action']);
        }

        if (filled($filters['actor'] ?? null)) {
            $query->where('actor_email', 'like', '%' . $filters['actor'] . '%');
        }

        if (filled($filters['entity_type'] ?? null)) {
            $query->where('entity_type', $filters['entity_type']);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function recent(int $limit = 10)
    {
        return AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('audit_id')
            ->limit($limit)
            ->get();
    }

    public function distinctActions(): array
    {
        return AuditLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }
}
