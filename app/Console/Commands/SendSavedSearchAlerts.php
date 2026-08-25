<?php

namespace App\Console\Commands;

use App\Models\SavedSearch;
use App\Services\NotificationService;
use App\Services\OpportunityService;
use App\Services\SavedSearchService;
use App\Support\NotificationType;
use Illuminate\Console\Command;

/**
 * Tells students when a newly published scholarship matches a search they saved.
 *
 * The match is the student's own saved filters run back through
 * OpportunityService::search(), so an alert can never disagree with what the
 * browse page would show them.
 *
 * Idempotence comes from a high-water mark per saved search rather than from one
 * row per (search, listing): opportunity ids only ever increase, so "anything
 * newer than the last id I told you about" is both cheap and exact. A repeated
 * run therefore sends nothing twice.
 */
class SendSavedSearchAlerts extends Command
{
    protected $signature = 'scholarzim:search-alerts';

    protected $description = 'Notify applicants when a new scholarship matches one of their saved searches';

    /** Titles quoted in the notification before it collapses to a count. */
    private const TITLES_IN_MESSAGE = 3;

    public function handle(
        SavedSearchService $savedSearches,
        OpportunityService $opportunities,
        NotificationService $notifications
    ): int {
        $this->info('Running saved-search alert job');

        $sent = 0;

        foreach ($savedSearches->alertable() as $search) {
            $applicant = $search->user;

            if ($applicant === null || ! $applicant->isActive()) {
                continue;
            }

            $matches = $opportunities
                ->searchAll($search->activeFilters())
                ->filter(fn ($opportunity) => $opportunity->opportunity_id > $search->last_alerted_opportunity_id)
                ->values();

            if ($matches->isEmpty()) {
                continue;
            }

            $notifications->notifyUser(
                $applicant,
                NotificationType::SCHOLARSHIP_SEARCH_MATCH,
                $this->message($search, $matches),
                '/opportunities?' . http_build_query($search->activeFilters()),
                $search->saved_search_id
            );

            $savedSearches->recordAlert($search, (int) $matches->max('opportunity_id'));
            $sent++;
        }

        $this->info("Saved-search alert job finished - {$sent} alert(s) sent");

        return self::SUCCESS;
    }

    private function message(SavedSearch $search, $matches): string
    {
        $count = $matches->count();
        $titles = $matches->take(self::TITLES_IN_MESSAGE)->pluck('title')->all();

        $headline = $count === 1
            ? 'A new scholarship matches your saved search "' . $search->name . '": '
            : $count . ' new scholarships match your saved search "' . $search->name . '": ';

        $listed = implode(', ', array_map(static fn (string $t) => '"' . $t . '"', $titles));
        $remainder = $count - count($titles);

        return $headline . $listed . ($remainder > 0 ? ' and ' . $remainder . ' more.' : '.');
    }
}
