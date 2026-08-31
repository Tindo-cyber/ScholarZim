<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Services\ScholarFit\ScholarFitEngine;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Runtime-editable settings, layered over the shipped config files.
 *
 * A value is read from platform_settings if a row exists, and from config()
 * otherwise, so a fresh install behaves exactly as the repository describes and
 * "reset to defaults" is a row delete rather than a second copy of the numbers.
 */
class SettingsService
{
    public const SCHOLARFIT_WEIGHTS = 'scholarfit.weights';

    private const CACHE_PREFIX = 'platform_setting.';

    public function __construct(private readonly AuditService $auditService)
    {
    }

    /** @return array<string, int> dimension => weight, always summing to 100 */
    public function scholarFitWeights(): array
    {
        $defaults = config('scholarfit.weights');
        $stored = $this->get(self::SCHOLARFIT_WEIGHTS);

        if (! is_array($stored)) {
            return $defaults;
        }

        // Only known dimensions survive, and anything the stored row is missing
        // falls back to its default - a partial row can never produce a total
        // that is not 100.
        $merged = array_map(
            static fn (string $key) => (int) ($stored[$key] ?? $defaults[$key]),
            array_combine(array_keys($defaults), array_keys($defaults))
        );

        return array_sum($merged) === 100 ? $merged : $defaults;
    }

    /**
     * @param  array<string, int>  $weights
     *
     * @throws ValidationException when the weights do not total 100
     */
    public function updateScholarFitWeights(array $weights, string $actorEmail): array
    {
        $defaults = config('scholarfit.weights');

        // Unknown criteria are refused rather than dropped. Silently ignoring
        // them meant an administrator could set an unknown criterion to 40, be shown a
        // saved-successfully message, and have the engine keep scoring on the
        // six dimensions it actually knows about.
        $unknown = array_diff(array_keys($weights), array_keys($defaults));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'weights' => 'Unknown scoring criteria: ' . implode(', ', $unknown) . '.',
            ]);
        }

        $clean = [];

        foreach (array_keys($defaults) as $dimension) {
            $value = (int) ($weights[$dimension] ?? 0);

            if ($value < 0 || $value > 100) {
                throw ValidationException::withMessages([
                    'weights.' . $dimension => 'Each weight must be between 0 and 100.',
                ]);
            }

            $clean[$dimension] = $value;
        }

        $total = array_sum($clean);

        if ($total !== 100) {
            throw ValidationException::withMessages([
                'weights' => 'Weights must add up to 100 - they currently total ' . $total . '.',
            ]);
        }

        $this->put(self::SCHOLARFIT_WEIGHTS, $clean, $actorEmail);

        $this->auditService->log(
            $actorEmail,
            AuditAction::UPDATE_SCHOLARFIT_WEIGHTS,
            'SETTING',
            null,
            'ScholarFit weights set to ' . json_encode($clean)
        );

        return $clean;
    }

    public function resetScholarFitWeights(string $actorEmail): array
    {
        $this->forget(self::SCHOLARFIT_WEIGHTS);

        $this->auditService->log(
            $actorEmail,
            AuditAction::UPDATE_SCHOLARFIT_WEIGHTS,
            'SETTING',
            null,
            'ScholarFit weights reset to the shipped defaults'
        );

        return config('scholarfit.weights');
    }

    /** True while the platform is running on the weights the repository ships. */
    public function scholarFitWeightsAreDefault(): bool
    {
        return $this->get(self::SCHOLARFIT_WEIGHTS) === null;
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

    /**
     * Bumped whenever a setting that changes scoring is written, so cached
     * ScholarFit rankings computed under the old weights are never served.
     */
    public function scoringVersion(): string
    {
        $weights = $this->scholarFitWeights();

        return substr(md5(json_encode($weights)), 0, 8);
    }

    /**
     * The full scoring identity: which engine, under which weights.
     *
     * "ScholarFit v2 (a1b2c3d4)". The engine version moves when the algorithm
     * changes and the hash moves when an administrator retunes it, so a score
     * quoted in a log or a report can be traced to both.
     */
    public function scoringIdentity(): string
    {
        return ScholarFitEngine::VERSION_LABEL . ' (' . $this->scoringVersion() . ')';
    }
}
