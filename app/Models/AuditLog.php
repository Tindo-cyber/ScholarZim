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
        'action',
        'entity_type',
        'entity_id',
        'details',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
