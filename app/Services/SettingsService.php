<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-editable settings, layered over the shipped config files.
 *
 * A value is read from platform_settings if a row exists, and from config()
 * otherwise, so a fresh install behaves exactly as the repository describes and
 * "reset to defaults" is a row delete rather than a second copy of the numbers.
 */
class SettingsService
{
    private const CACHE_PREFIX = 'platform_setting.';

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /**
     * A missing platform_settings table means "no override", not an error: the
     * settings layer must not be a hard dependency of scoring, which has to keep
     * working on a fresh install before the migration has run, and in unit tests
     * that never touch a database.
     */
    public function get(string $key): mixed
    {
        return Cache::remember(
            self::CACHE_PREFIX . $key,
            now()->addMinutes(30),
            static function () use ($key) {
                try {
                    return PlatformSetting::find($key)?->value;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        );
    }

    public function put(string $key, mixed $value, ?string $actorEmail = null): void
    {
        PlatformSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $actorEmail, 'updated_at' => Carbon::now()]
        );

        Cache::forget(self::CACHE_PREFIX . $key);
    }

    public function forget(string $key): void
    {
        PlatformSetting::where('key', $key)->delete();

        Cache::forget(self::CACHE_PREFIX . $key);
    }
}
