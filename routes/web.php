<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Applicant;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\Provider;
use App\Http\Controllers\PublicController;
use App\Support\RoleNames;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
| Open to guests. Scholarship detail is deliberately public so listings are
| shareable and indexable; only approved posts are reachable.
*/

// no_store: this page renders differently for a guest and a signed-in user
// ("Sign in" vs "Go to dashboard"), and a cached copy restored after the auth
// state changed would show the wrong one.
Route::get('/', [PublicController::class, 'landing'])->middleware('cache.headers:no_store')->name('home');
Route::get('/how-scholarfit-works', [PublicController::class, 'scholarFit'])->name('scholarfit');
Route::get('/how-it-works', [PublicController::class, 'howItWorks'])->name('how-it-works');
Route::get('/scholarships', [PublicController::class, 'scholarships'])->name('scholarships.index');
Route::get('/scholarships/{id}', [PublicController::class, 'detail'])
    ->whereNumber('id')
    ->name('scholarships.show');

// Liveness: no dependencies, so a failure means restart this process.
Route::get('/health', [\App\Http\Controllers\HealthController::class, 'live'])->name('health');

// Readiness: checks the dependencies a request needs, so a database outage takes
// the instance out of rotation without restarting a process that is working fine.
Route::get('/health/ready', [\App\Http\Controllers\HealthController::class, 'ready'])->name('health.ready');

// Fallback asset path, used only when no Vite build is present. See
// SourceAssetController for why it exists.
Route::get('/assets/source/{asset}', [\App\Http\Controllers\SourceAssetController::class, 'show'])
    ->where('asset', '[A-Za-z0-9.\-]+')
    ->name('assets.source');

/*
 * Installable-app files. Public by necessity - a browser fetches the manifest
 * and registers the worker before anyone signs in - and public by design: none
 * of the three reads a session or returns a record. See PwaController.
 *
 * The worker is registered at the site root because a service worker may only
 * control the path it was served from and below; moved into a subdirectory it
 * would stop seeing the navigations it exists to answer.
 */
// No session for any of the three. The browser fetches them in the background
// (the service worker is re-checked on navigation) carrying the visitor's session
// cookie, and a request that runs the session pipeline uses up the flash data
// Laravel keeps for exactly one following request. When one of these landed
// between a form's POST and its redirected GET it consumed the flashed
// validation errors / "You have been signed out" / "Profile saved" message, and
// the page rendered without it - intermittently, for every role. They read no
// session and return no per-user content (see PwaController), so they skip it,
// and with it the session cookie they would otherwise re-issue.
//
// The list is every session-dependent middleware in the `web` group: those that
// need a session on the request (CSRF cookie, shared errors, session
// authentication) would throw without one, and the suspended-account check has
// no user to look at. Cookie encryption and the security headers still apply.
Route::withoutMiddleware([
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\Session\Middleware\AuthenticateSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \App\Http\Middleware\BlockSuspendedAccounts::class,
    \App\Http\Middleware\VerifyCsrfToken::class,
])->group(function () {
    Route::get('/manifest.webmanifest', [\App\Http\Controllers\PwaController::class, 'manifest'])->name('pwa.manifest');
    Route::get('/service-worker.js', [\App\Http\Controllers\PwaController::class, 'serviceWorker'])->name('pwa.service-worker');
    Route::get('/offline', [\App\Http\Controllers\PwaController::class, 'offline'])->name('pwa.offline');
});

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/

// no_store: a browser's back-forward cache can otherwise restore one of these
// pages verbatim after the auth state that produced it has changed - most
// visibly, landing back on a rendered "/login" after having signed in and out
// again, showing whatever it looked like at the moment it was cached rather
// than what the server would render now.
//
// Sign-in is deliberately outside `guest`: it is where logout lands, and
// where the back button lands after signing in, so it always renders the
// form - a signed-in visitor is not bounced to the landing page. Reaching it
// with a live session ends that session (so Back signs you out and Forward
// asks for credentials again), and posting it starts a fresh one; see
// LoginController::showLoginForm() and ::login().
Route::middleware('cache.headers:no_store')->group(function () {
    Route::get('/login', [Auth\LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [Auth\LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['guest', 'cache.headers:no_store'])->group(function () {
    Route::get('/register', [Auth\RegisterController::class, 'showApplicantForm'])->name('register');
    Route::post('/register', [Auth\RegisterController::class, 'registerApplicant'])
        ->middleware('throttle:10,1');

    Route::get('/register/provider', [Auth\RegisterController::class, 'showProviderForm'])->name('register.provider');
    // Provider sign-up is heavier (certificate upload + admin review), so it is
    // held to the same 5-per-hour ceiling the Spring app applied.
    Route::post('/register/provider', [Auth\RegisterController::class, 'registerProvider'])
        ->middleware('throttle:5,60');

    Route::get('/forgot-password', [Auth\PasswordResetController::class, 'showRequestForm'])->name('password.request');
    Route::post('/forgot-password', [Auth\PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [Auth\PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password/{token}', [Auth\PasswordResetController::class, 'reset'])->name('password.update');
});

Route::get('/verify-email/{token}', [Auth\EmailVerificationController::class, 'verify'])->name('verification.verify');
Route::post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Signed in, any role
|--------------------------------------------------------------------------
*/

// Same no_store reasoning as the guest group above, for the signed-in side:
// a cached dashboard/account page must not be replayable by the back button
// after sign-out.
Route::middleware(['auth', 'cache.headers:no_store'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/verify-email', [Auth\EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/resend-verification', [Auth\EmailVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('verification.resend');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])
        ->whereNumber('id')
        ->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');

    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');
    Route::post('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::post('/account/notification-preferences', [AccountController::class, 'updateNotificationPreferences'])
        ->name('account.notifications');

    Route::post('/account/logout-other-sessions', [AccountController::class, 'logoutOtherSessions'])
        ->name('account.logoutOthers');
    Route::post('/account/delete', [AccountController::class, 'destroyAccount'])->name('account.destroy');

    Route::get('/opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');

    Route::get('/applications/{applicationId}/document', [FileDownloadController::class, 'applicationDocument'])
        ->whereNumber('applicationId')
        ->name('files.applicationDocument');
    Route::get('/my-documents/{documentType}', [FileDownloadController::class, 'myDocument'])
        ->name('files.myDocument');
});

/*
|--------------------------------------------------------------------------
| Applicants
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:' . RoleNames::APPLICANT, 'cache.headers:no_store'])->group(function () {
    Route::get('/applicant/dashboard', [Applicant\DashboardController::class, 'index'])->name('applicant.dashboard');

    Route::get('/applicant/profile', [Applicant\ProfileController::class, 'edit'])->name('applicant.profile');
    Route::post('/applicant/profile', [Applicant\ProfileController::class, 'update'])->name('applicant.profile.update');
    Route::post('/applicant/profile/programmes', [Applicant\ProfileController::class, 'programmes'])->name('applicant.profile.programmes');
    Route::post('/applicant/profile/confirm-level', [Applicant\ProfileController::class, 'confirmLevel'])->name('applicant.profile.confirmLevel');
    Route::post('/applicant/profile/documents/{documentType}', [Applicant\ProfileController::class, 'uploadDocument'])
        ->name('applicant.profile.documents');

    Route::get('/applicant/recommendations', [Applicant\RecommendationController::class, 'index'])
        ->name('applicant.recommendations');

    // Reporting a listing. Limited per account (see AppServiceProvider), because a
    // report is a cost to the provider it names.
    Route::post('/scholarships/{id}/report', [Applicant\ListingReportController::class, 'store'])
        ->whereNumber('id')
        ->middleware('throttle:listing-reports')
        ->name('listing.report');

    Route::get('/applicant/saved', [Applicant\SavedScholarshipController::class, 'index'])->name('applicant.saved');
    Route::post('/applicant/saved/{id}', [Applicant\SavedScholarshipController::class, 'store'])
        ->whereNumber('id')
        ->name('applicant.saved.store');
    Route::post('/applicant/saved/{id}/remove', [Applicant\SavedScholarshipController::class, 'destroy'])
        ->whereNumber('id')
        ->name('applicant.saved.destroy');

    Route::get('/my-applications', [ApplicationController::class, 'myApplications'])->name('applications.mine');

    Route::post('/applications/{applicationId}/withdraw', [ApplicationController::class, 'withdraw'])
        ->whereNumber('applicationId')
        ->name('applications.withdraw');
    Route::get('/apply/{opportunityId}', [ApplicationController::class, 'showWizard'])
        ->whereNumber('opportunityId')
        ->name('applications.wizard');
    Route::post('/apply/{opportunityId}', [ApplicationController::class, 'submit'])
        ->whereNumber('opportunityId')
        ->name('applications.submit');
    Route::post('/apply/{opportunityId}/quick', [ApplicationController::class, 'quickApply'])
        ->whereNumber('opportunityId')
        ->name('applications.quick');
    Route::get('/applications/{applicationId}/confirmation', [ApplicationController::class, 'confirmation'])
        ->whereNumber('applicationId')
        ->name('applications.confirmation');
});

/*
|--------------------------------------------------------------------------
| Providers
|--------------------------------------------------------------------------
| The dashboard stays reachable while verification is pending so a provider can
| see their status; publishing routes additionally require an active account.
*/

Route::middleware(['auth', 'role:' . RoleNames::PROVIDER, 'cache.headers:no_store'])->group(function () {
    Route::get('/provider/dashboard', [Provider\DashboardController::class, 'index'])->name('provider.dashboard');

    Route::get('/provider/analytics', [Provider\AnalyticsController::class, 'index'])->name('provider.analytics');

    // The organisation's website, which an administrator then confirms.
    Route::post('/provider/website', [Provider\WebsiteController::class, 'update'])->name('provider.website.update');

    Route::get('/provider/applications', [Provider\ApplicationReviewController::class, 'index'])
        ->name('provider.applications');
    Route::get('/provider/applications/{id}', [Provider\ApplicationReviewController::class, 'show'])
        ->whereNumber('id')
        ->name('provider.applications.show');
    Route::post('/provider/applications/{id}/review', [Provider\ApplicationReviewController::class, 'review'])
        ->whereNumber('id')
        ->name('provider.applications.review');
    Route::get('/provider/applications/{applicationId}/results-certificate', [FileDownloadController::class, 'applicantResults'])
        ->whereNumber('applicationId')
        ->name('files.applicantResults');
    Route::get('/provider/applications/{applicationId}/transcript', [FileDownloadController::class, 'applicantTranscript'])
        ->whereNumber('applicationId')
        ->name('files.applicantTranscript');

    Route::middleware('account.active')->group(function () {
        Route::get('/opportunities/create', [OpportunityController::class, 'create'])->name('opportunities.create');
        Route::post('/opportunities/create', [OpportunityController::class, 'store'])->name('opportunities.store');

        // The public page for a half-filled form. Accepts PUT as well as POST: the edit
        // form carries a hidden _method=PUT, and its Preview button posts the same fields.
        Route::match(['POST', 'PUT'], '/opportunities/preview', [OpportunityController::class, 'preview'])
            ->middleware('throttle:60,1')
            ->name('opportunities.preview');

        // Drafts: saved without full validation, never public, discarded outright.
        Route::post('/opportunities/draft', [OpportunityController::class, 'saveDraft'])
            ->name('opportunities.draft.save');
        Route::delete('/opportunities/{id}/draft', [OpportunityController::class, 'discardDraft'])
            ->whereNumber('id')
            ->name('opportunities.draft.discard');

        Route::get('/opportunities/{id}/duplicate', [OpportunityController::class, 'duplicate'])
            ->whereNumber('id')
            ->name('opportunities.duplicate');

        Route::get('/opportunities/{id}/edit', [OpportunityController::class, 'edit'])
            ->whereNumber('id')
            ->name('opportunities.edit');
        Route::put('/opportunities/{id}', [OpportunityController::class, 'update'])
            ->whereNumber('id')
            ->name('opportunities.update');
        Route::post('/opportunities/{id}/edit-impact', [OpportunityController::class, 'editImpact'])
            ->whereNumber('id')
            ->middleware('throttle:60,1')
            ->name('opportunities.editImpact');
        Route::post('/opportunities/{id}/extend-deadline', [OpportunityController::class, 'extendDeadline'])
            ->whereNumber('id')
            ->name('opportunities.extendDeadline');
        Route::delete('/opportunities/{id}', [OpportunityController::class, 'destroy'])
            ->whereNumber('id')
            ->name('opportunities.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Administrators
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:' . RoleNames::ADMIN, 'cache.headers:no_store'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/audit-log', [Admin\AuditLogController::class, 'index'])->name('audit');

    // mail:check, for the deployment that has no shell to run it in. Read-only
    // and administrator-only; it never sends a message and never returns the
    // credential, only a fingerprint of it.
    Route::get('/mail-diagnostics', Admin\MailDiagnosticsController::class)->name('mail.diagnostics');

    // One deliberately-logged line, to confirm whether production logging
    // actually reaches anywhere visible - see Admin\LogDiagnosticsController.
    Route::get('/log-diagnostics', Admin\LogDiagnosticsController::class)->name('log.diagnostics');

    Route::get('/search', [Admin\SearchController::class, 'index'])->name('search');

    Route::get('/reports', [Admin\ReportController::class, 'hub'])->name('reports');
    Route::get('/reports/users.pdf', [Admin\ReportController::class, 'usersPdf'])->name('reports.users.pdf');
    Route::get('/reports/opportunities.pdf', [Admin\ReportController::class, 'opportunitiesPdf'])->name('reports.opportunities.pdf');
    Route::get('/reports/applications.pdf', [Admin\ReportController::class, 'applicationsPdf'])->name('reports.applications.pdf');
    Route::get('/reports/recommendations.pdf', [Admin\ReportController::class, 'recommendationsPdf'])->name('reports.recommendations.pdf');
    Route::get('/reports/users.xlsx', [Admin\ReportController::class, 'usersExcel'])->name('reports.users.xlsx');
    Route::get('/reports/opportunities.xlsx', [Admin\ReportController::class, 'opportunitiesExcel'])->name('reports.opportunities.xlsx');
    Route::get('/reports/applications.xlsx', [Admin\ReportController::class, 'applicationsExcel'])->name('reports.applications.xlsx');

    Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [Admin\UserController::class, 'create'])->name('users.create');
    Route::post('/users/create', [Admin\UserController::class, 'store'])->name('users.store');
    Route::post('/users/{id}/suspend', [Admin\UserController::class, 'suspend'])->whereNumber('id')->name('users.suspend');
    Route::post('/users/{id}/reactivate', [Admin\UserController::class, 'reactivate'])->whereNumber('id')->name('users.reactivate');
    Route::post('/users/{id}/delete', [Admin\UserController::class, 'destroy'])->whereNumber('id')->name('users.destroy');

    Route::post('/users/providers/{id}/approve', [Admin\UserController::class, 'approveProvider'])
        ->whereNumber('id')
        ->name('providers.approve');
    Route::post('/users/providers/{id}/website', [Admin\ProviderWebsiteController::class, 'update'])
        ->whereNumber('id')
        ->name('providers.website');
    Route::post('/users/providers/{id}/trust', [Admin\ProviderTrustController::class, 'update'])
        ->whereNumber('id')
        ->name('providers.trust');
    Route::post('/users/providers/{id}/reject', [Admin\UserController::class, 'rejectProvider'])
        ->whereNumber('id')
        ->name('providers.reject');
    Route::get('/providers/{userId}/certificate', [FileDownloadController::class, 'providerCertificate'])
        ->whereNumber('userId')
        ->name('providers.certificate');
    Route::get('/providers/{userId}/certificate/diagnose', [Admin\StorageDiagnosticsController::class, 'providerCertificate'])
        ->whereNumber('userId')
        ->name('providers.certificate.diagnose');
    Route::get('/storage-diagnostics/write-test', [Admin\StorageDiagnosticsController::class, 'writeTest'])
        ->name('storage.diagnostics.write-test');

    Route::post('/opportunities/bulk-review', [Admin\ModerationController::class, 'bulkReview'])
        ->name('moderation.bulk');

    // The programme catalogue: what students study and what scholarships are open to.
    Route::prefix('catalogue')->group(function () {
        Route::get('/', [Admin\CatalogueController::class, 'index'])->name('catalogue');
        Route::get('/programmes', [Admin\CatalogueController::class, 'programmes'])->name('catalogue.programmes');
        Route::get('/programmes/create', [Admin\CatalogueController::class, 'createProgramme'])->name('catalogue.programmes.create');
        Route::post('/programmes', [Admin\CatalogueController::class, 'storeProgramme'])->name('catalogue.programmes.store');
        Route::get('/programmes/{id}/edit', [Admin\CatalogueController::class, 'editProgramme'])->whereNumber('id')->name('catalogue.programmes.edit');
        Route::post('/programmes/{id}', [Admin\CatalogueController::class, 'updateProgramme'])->whereNumber('id')->name('catalogue.programmes.update');
        Route::get('/fields', [Admin\CatalogueController::class, 'fields'])->name('catalogue.fields');
        Route::post('/fields', [Admin\CatalogueController::class, 'storeField'])->name('catalogue.fields.store');
        Route::post('/fields/{id}', [Admin\CatalogueController::class, 'updateField'])->whereNumber('id')->name('catalogue.fields.update');
        Route::get('/institutions', [Admin\CatalogueController::class, 'institutions'])->name('catalogue.institutions');
        Route::post('/institutions', [Admin\CatalogueController::class, 'storeInstitution'])->name('catalogue.institutions.store');
        Route::post('/institutions/{id}', [Admin\CatalogueController::class, 'updateInstitution'])->whereNumber('id')->name('catalogue.institutions.update');
        Route::get('/pending', [Admin\CatalogueController::class, 'pending'])->name('catalogue.pending');
        Route::get('/old-values', [Admin\CatalogueController::class, 'oldValues'])->name('catalogue.oldValues');
        Route::post('/old-values', [Admin\CatalogueController::class, 'mapOldValue'])->name('catalogue.oldValues.map');
        Route::post('/old-values/migrate', [Admin\CatalogueController::class, 'migrateOldFields'])->name('catalogue.oldValues.migrate');
        Route::post('/old-values/{id}/delete', [Admin\CatalogueController::class, 'deleteAlias'])->whereNumber('id')->name('catalogue.oldValues.delete');
        Route::post('/pending/{id}/approve', [Admin\CatalogueController::class, 'approve'])->whereNumber('id')->name('catalogue.pending.approve');
        Route::post('/pending/{id}/merge', [Admin\CatalogueController::class, 'merge'])->whereNumber('id')->name('catalogue.pending.merge');
        Route::post('/pending/{id}/reject', [Admin\CatalogueController::class, 'reject'])->whereNumber('id')->name('catalogue.pending.reject');
        Route::post('/import', [Admin\CatalogueController::class, 'import'])->middleware('throttle:20,1')->name('catalogue.import');
        Route::get('/export/{kind}.{format}', [Admin\CatalogueController::class, 'export'])->name('catalogue.export');
    });

    // Listings students have reported.
    Route::get('/listing-reports', [Admin\ListingReportController::class, 'index'])->name('listing-reports');
    Route::post('/listing-reports/{id}/dismiss', [Admin\ListingReportController::class, 'dismiss'])
        ->whereNumber('id')
        ->name('listing-reports.dismiss');
    Route::post('/listing-reports/{id}/uphold', [Admin\ListingReportController::class, 'uphold'])
        ->whereNumber('id')
        ->name('listing-reports.uphold');

    // Listings a trusted provider put live without review, for checking afterwards.
    Route::get('/auto-published', [Admin\AutoPublishedController::class, 'index'])->name('auto-published');
    Route::post('/auto-published/{id}/confirm', [Admin\AutoPublishedController::class, 'confirm'])
        ->whereNumber('id')
        ->name('auto-published.confirm');
    Route::post('/auto-published/{id}/unpublish', [Admin\AutoPublishedController::class, 'unpublish'])
        ->whereNumber('id')
        ->name('auto-published.unpublish');

    Route::get('/opportunities/{id}', [Admin\ModerationController::class, 'show'])
        ->whereNumber('id')
        ->name('moderation.show');
    Route::post('/opportunities/{id}/approve', [Admin\ModerationController::class, 'approve'])
        ->whereNumber('id')
        ->name('moderation.approve');
    Route::post('/opportunities/{id}/reject', [Admin\ModerationController::class, 'reject'])
        ->whereNumber('id')
        ->name('moderation.reject');
});
