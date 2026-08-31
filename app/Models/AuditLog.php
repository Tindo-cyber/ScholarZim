<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';

    protected $primaryKey = 'audit_id';

    public $timestamps = false;

    protected $fillable = [
        'actor_email',
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'old_values',
        'new_values',
        'reason',
        'ip_address',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /** Whether this entry recorded what actually changed. */
    public function hasValueChanges(): bool
    {
        return filled($this->old_values) || filled($this->new_values);
    }

    /**
     * The changed fields as before/after pairs, for the audit screen.
     *
     * @return array<int, array{field: string, old: mixed, new: mixed}>
     */
    public function changedFields(): array
    {
        $keys = array_unique(array_merge(
            array_keys($this->old_values ?? []),
            array_keys($this->new_values ?? [])
        ));

        return array_map(fn (string $key) => [
            'field' => $key,
            'old' => $this->old_values[$key] ?? null,
            'new' => $this->new_values[$key] ?? null,
        ], $keys);
    }
}
