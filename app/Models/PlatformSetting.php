<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value overrides for settings an administrator can change at runtime.
 * Read through SettingsService, never directly - the service is what falls back
 * to the shipped config when no row exists.
 */
class PlatformSetting extends Model
{
    protected $table = 'platform_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
        'updated_by',
        'updated_at',
    ];

    protected $casts = [
        'value' => 'array',
        'updated_at' => 'datetime',
    ];
}
