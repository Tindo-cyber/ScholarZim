<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Append-only trail. Audit failures must never break the action being
     * audited, so problems are logged and swallowed.
     */
    public function log(
        string $actorEmail,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $details = null
    ): void {
        try {
            AuditLog::create([
                'actor_email' => $actorEmail,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit write failed', [
                'action' => $action,
                'actor' => $actorEmail,
                'error' => $e->getMessage(),
            ]);
        }
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
