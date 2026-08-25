<?php

namespace App\Services;

use App\Models\SavedSearch;
use App\Models\User;
use App\Support\AuditAction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Saved searches and the alerts they drive.
 *
 * A saved search is just the browse filters the student was looking at when they
 * pressed save, so an alert run re-executes the very search they saw - there is
 * no second matching implementation to drift out of step with the catalogue page.
 */
class SavedSearchService
{
    /** More than this and the alert emails stop being useful to anyone. */
    public const MAX_PER_USER = 10;

    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function forUser(User $user)
    {
        return SavedSearch::where('user_id', $user->user_id)
            ->orderByDesc('created_at')
            ->get();
    }

    public function countForUser(User $user): int
    {
        return SavedSearch::where('user_id', $user->user_id)->count();
    }

    /**
     * @param  array<string, mixed>  $filters  raw query-string filters
     *
     * @throws ValidationException when the ceiling is reached
     */
    public function create(User $user, string $name, array $filters, bool $alertsEnabled = true): SavedSearch
    {
        if ($this->countForUser($user) >= self::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'name' => 'You already have ' . self::MAX_PER_USER . ' saved searches. Delete one first.',
            ]);
        }

        $clean = $this->cleanFilters($filters);

        $search = SavedSearch::create([
            'user_id' => $user->user_id,
            'name' => trim($name),
            'filters' => $clean,
            'alerts_enabled' => $alertsEnabled,
            // Everything already published counts as seen: saving a search must
            // not blast the student with the entire back catalogue tomorrow
            // morning.
            'last_alerted_opportunity_id' => $this->currentHighWaterMark(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->auditService->log(
            $user->email,
            AuditAction::SAVED_SEARCH_CREATED,
            'SAVED_SEARCH',
            $search->saved_search_id,
            'Saved the search "' . $search->name . '"'
        );

        return $search;
    }

    public function toggleAlerts(int $savedSearchId, User $user): SavedSearch
    {
        $search = $this->findOwnedOrFail($savedSearchId, $user);

        $search->update([
            'alerts_enabled' => ! $search->alerts_enabled,
            'updated_at' => Carbon::now(),
        ]);

        return $search;
    }

    public function delete(int $savedSearchId, User $user): void
    {
        $this->findOwnedOrFail($savedSearchId, $user)->delete();
    }

    public function findOwnedOrFail(int $savedSearchId, User $user): SavedSearch
    {
        $search = SavedSearch::where('saved_search_id', $savedSearchId)
            ->where('user_id', $user->user_id)
            ->first();

        abort_if($search === null, 404);

        return $search;
    }

    /** Searches the daily alert job should run. */
    public function alertable()
    {
        return SavedSearch::with('user')
            ->where('alerts_enabled', true)
            ->orderBy('saved_search_id')
            ->get();
    }

    public function recordAlert(SavedSearch $search, int $highestOpportunityId): void
    {
        $search->update([
            'last_alerted_opportunity_id' => max($highestOpportunityId, $search->last_alerted_opportunity_id),
            'last_alerted_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Keeps only the keys the search engine understands, so a crafted query
     * string cannot smuggle an arbitrary column into a stored filter set.
     */
    private function cleanFilters(array $filters): array
    {
        $allowed = array_intersect_key($filters, array_flip(SavedSearch::FILTER_KEYS));

        return array_map(
            static fn ($value) => is_string($value) ? trim($value) : $value,
            array_filter($allowed, static fn ($value) => filled($value))
        );
    }

    private function currentHighWaterMark(): int
    {
        return (int) \App\Models\Opportunity::query()->max('opportunity_id');
    }
}
