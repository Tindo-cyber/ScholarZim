<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\RoleNames;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Admin PDF exports. Mirrors the OpenPDF documents the Spring app produced —
 * same landscape A4 pages, columns and ordering — but composed from Blade
 * views rather than built cell by cell.
 */
class ReportService
{
    public function __construct(private readonly RecommendationService $recommendationService)
    {
    }

    public function usersReportPdf(): string
    {
        return $this->render('Users Report', 'reports.users', [
            'users' => User::with('role')->orderBy('user_id')->get(),
        ]);
    }

    public function opportunitiesReportPdf(): string
    {
        return $this->render('Opportunities Report', 'reports.opportunities', [
            'opportunities' => Opportunity::orderBy('opportunity_id')->get(),
        ]);
    }

    public function applicationsReportPdf(): string
    {
        return $this->render('Applications Report', 'reports.applications', [
            'applications' => Application::with(['user', 'opportunity'])->orderBy('application_id')->get(),
        ]);
    }

    public function recommendationsReportPdf(): string
    {
        $sections = [];

        $applicants = User::with('applicantProfile')
            ->whereHas('role', fn ($query) => $query->where('role_name', RoleNames::APPLICANT))
            ->orderBy('user_id')
            ->get();

        foreach ($applicants as $applicant) {
            $matches = $this->recommendationService->forUser($applicant);

            // Applicants with no viable match are skipped rather than printed empty.
            if ($matches === []) {
                continue;
            }

            $sections[] = ['applicant' => $applicant, 'matches' => $matches];
        }

        return $this->render('Recommendation Report', 'reports.recommendations', [
            'sections' => $sections,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function render(string $title, string $view, array $data): string
    {
        return Pdf::loadView($view, $data + ['title' => $title])
            ->setPaper('a4', 'landscape')
            ->output();
    }
}
