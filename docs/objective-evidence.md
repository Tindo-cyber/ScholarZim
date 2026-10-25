# Dissertation objectives: implementation and evidence

Each objective is mapped to the module that implements it, where to see it, the automated test that covers it, and what was
actually run. "Verified" means the test files named were executed and passed (results below, run 2026-10-25 on `main`);
it does not mean the whole suite was re-run for this document.

| # | Objective | Status |
|---|-----------|--------|
| 1 | Centralised scholarship discovery, saving and application | Verified |
| 2 | ScholarFit recommendations and eligibility explanations | Verified |
| 3 | Application submission and status tracking | Verified |
| 4 | Verified-provider scholarship administration and application decisions | Verified |
| 5 | Administrator reports, including system activities | Verified (see the note on scale) |

## 1. Centralised scholarship discovery, saving and application

- **Modules:** `PublicController` (search and listing pages), `Applicant\SavedScholarshipController`, `ApplicationController`,
  `ApplicationService`, `ApplicationReadiness`, `DocumentChecklist`.
- **Screens / routes:** `/scholarships` (search and filters), `/scholarships/{id}`, `/applicant/saved`, `/apply/{id}`.
- **Tests:** `ScholarshipDiscoveryTest`, `ScholarshipJourneyTest`, `AuthorizationTest` (saved-list access),
  `ApplicationReuseTest` and `DocumentAvailabilityTest` (the apply page and submission gate).
- **Application rules that were hardened:** a required document counts only when its file can be opened;
  a storage outage is reported as such and never as a missing file; the number of awards is required to publish;
  Form 1, O-Level and A-Level scholarships are for study in Zimbabwe.

## 2. ScholarFit recommendations and eligibility explanations

- **Modules:** `App\Services\ScholarFit\*` (`EligibilityEvaluator`, `EligibilityResult`, `RequirementOutcome`),
  `RecommendationService`. ScholarFit is eligibility-only: it states whether each stated requirement is met, not a score.
- **Screens / routes:** `/applicant/recommendations`, the eligibility panel on each listing, the apply page.
- **Tests:** `ScholarFitAccuracyTest` (answer key of applicant-by-listing pairs), `EligibilityExplanationTest`,
  `RecommendationTest`.
- **Not claimed:** machine-learning recommendations (see Future work).

## 3. Application submission and status tracking

- **Modules:** `ApplicationService`, `ApplicationStatus`, `ApplicationStateMachine`, `Application::trackerStages()`.
- **Screens / routes:** `/apply/{id}`, `/my-applications`, `/applications/{id}/confirmation` (status timeline and history).
- **Tests:** `ApplicationLifecycleTest`, `ApplicationTrackerTest`, `ApplicationGateTest`, `DocumentAvailabilityTest`
  (a missing document blocks submission; an applicant can repair a pending application's document).

## 4. Verified-provider scholarship administration and application decisions

- **Modules:** `OpportunityController`, `OpportunityService`, `OpportunityModerationService`, `ProviderTrust`,
  `Provider\ApplicationReviewController`, `FileDownloadController` (provider access to submitted documents, audited).
- **Screens / routes:** `/opportunities/create`, `/provider/applications`, `/provider/applications/{id}`, admin moderation.
- **Tests:** `ProviderVerificationTest`, `TrustedPublishingTest`, `OpportunityLifecycleTest`, `ProviderListingWorkflowTest`,
  `ApplicationDecisionFinalTest` (decisions are final), `ProviderDocumentAccessTest` and `DocumentAvailabilityTest`
  (only the listing's own provider can open documents), `ListingValidationTest` (award count, country rule).

## 5. Administrator reports, including system activities

- **Modules:** `Admin\ReportController`, `ReportService` (PDF), `ExcelReportService` (Excel), `AuditLog`, `AuditService`.
- **Screens / routes:** `/admin/reports` lists Users, Opportunities, Applications, **System activities** and Recommendations.
  `/admin/reports/activities.pdf` and `/admin/reports/activities.xlsx` export the audit history; the on-screen audit log
  (`/admin/audit`, with its filters) is unchanged.
- **What the activity exports contain:** timestamp, actor, action, record type and ID, details, reason; the Excel file also
  has actor ID, the before-and-after values, IP address and request ID. Newest first. Entries are read from `audit_log`;
  nothing is generated or altered. Values were redacted when written by `AuditService`, so credentials are not in the data.
- **Access:** administrators only (route group plus the `export-reports` gate); guests go to sign-in, other roles are refused.
- **Tests:** `ReportExportTest` (all PDF and Excel downloads including activities, hub listing, real audit rows in the
  right order with detail, history unchanged by exporting, guests and non-administrators refused).
- **Scale note:** the PDF lists the 1,000 most recent activities and says so; the Excel file has every entry.

## Verification run

- `php artisan test --filter='ReportExportTest|DocumentAvailabilityTest|ProviderDocumentAccessTest|ListingValidationTest|ListingCountryTest' --stop-on-failure`
  - 186 passed (773 assertions).
- Objective 1-4 evidence files (`ScholarshipDiscoveryTest`, `ScholarshipJourneyTest`, `ScholarFitAccuracyTest`,
  `EligibilityExplanationTest`, `RecommendationTest`, `ApplicationTrackerTest`, `ApplicationLifecycleTest`,
  `ApplicationDecisionFinalTest`, `ProviderVerificationTest`, `TrustedPublishingTest`, `OpportunityLifecycleTest`,
  `ProviderListingWorkflowTest`, `ApplicationGateTest`, `AuthorizationTest`) - 275 passed (1,471 assertions).

## Future work (not implemented, not claimed)

- Offline-capable mobile access
- SMS reminders (deliberately removed from scope; email and in-app notifications are the delivery channels)
- Machine-learning recommendations (ScholarFit is rule-based and eligibility-only)
