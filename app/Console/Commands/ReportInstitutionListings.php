<?php

namespace App\Console\Commands;

use App\Models\Opportunity;
use Illuminate\Console\Command;

/**
 * Read-only: the listings that name institutions, so their providers can be asked what they meant.
 *
 * Before the study-destination rule an institution on a listing was compared with where an applicant studied or had
 * applied, so "MSU" meant "students at MSU". It now means "to be studied at MSU" and applicants are not checked against
 * it. A listing that really meant the first cannot be told apart from one that meant the second, so nothing is
 * rewritten here - this only lists them, with how many applications each has, for someone to review.
 */
class ReportInstitutionListings extends Command
{
    protected $signature = 'scholarzim:report-institution-listings';

    protected $description = 'List listings that name institutions (read-only), to review what their providers meant';

    public function handle(): int
    {
        $rows = Opportunity::query()
            ->notDraft()
            ->where(function ($q) {
                $q->where('any_institution', false)
                    ->orWhereHas('scopes', fn ($s) => $s->whereNotNull('institution_id'))
                    ->orWhereNotNull('custom_institutions')
                    ->orWhere(fn ($h) => $h->whereNotNull('host_institutions')->where('host_institutions', '<>', ''));
            })
            ->with(['scopes.institution', 'provider'])
            ->withCount('applications')
            ->orderBy('opportunity_id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No listing names an institution.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Title', 'Status', 'Provider', 'Institutions', 'Applications'],
            $rows->map(fn (Opportunity $o) => [
                $o->opportunity_id,
                mb_strimwidth($o->title, 0, 44, '…'),
                $o->lifecycleLabel(),
                $o->provider?->email,
                implode('; ', array_filter([
                    $o->scopes->pluck('institution.name')->filter()->implode(', '),
                    collect((array) $o->custom_institutions)->pluck('name')->filter()->implode(', '),
                    $o->host_institutions,
                ])) ?: '(any institution is false, none chosen)',
                $o->applications_count,
            ])->all()
        );

        $this->line($rows->count() . ' listing(s). Their institutions are now read as where the award is studied; nothing was changed.');

        return self::SUCCESS;
    }
}
