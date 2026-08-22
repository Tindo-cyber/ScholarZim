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

Route::get('/', [PublicController::class, 'landing'])->name('home');
Route::get('/scholarships', [PublicController::class, 'scholarships'])->name('scholarships.index');
Route::get('/scholarships/{id}', [PublicController::class, 'detail'])
    ->whereNumber('id')
    ->name('scholarships.show');

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {
    Route::get('/login', [Auth\LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [Auth\LoginController::class, 'login'])->middleware('throttle:10,1');

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

Route::middleware('auth')->group(function () {
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
    Route::get('/account/export-data', [AccountController::class, 'exportData'])->name('account.export');

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

Route::middleware(['auth', 'role:' . RoleNames::APPLICANT])->group(function () {
    Route::get('/applicant/dashboard', [Applicant\DashboardController::class, 'index'])->name('applicant.dashboard');

    Route::get('/applicant/profile', [Applicant\ProfileController::class, 'edit'])->name('applicant.profile');
    Route::post('/applicant/profile', [Applicant\ProfileController::class, 'update'])->name('applicant.profile.update');
    Route::post('/applicant/profile/documents/{documentType}', [Applicant\ProfileController::class, 'uploadDocument'])
        ->name('applicant.profile.documents');

    Route::get('/applicant/recommendations', [Applicant\RecommendationController::class, 'index'])
        ->name('applicant.recommendations');

    Route::get('/applicant/saved', [Applicant\SavedScholarshipController::class, 'index'])->name('applicant.saved');
    Route::post('/applicant/saved/{id}', [Applicant\SavedScholarshipController::class, 'store'])
        ->whereNumber('id')
        ->name('applicant.saved.store');
    Route::post('/applicant/saved/{id}/remove', [Applicant\SavedScholarshipController::class, 'destroy'])
        ->whereNumber('id')
        ->name('applicant.saved.destroy');

    Route::get('/my-applications', [ApplicationController::class, 'myApplications'])->name('applications.mine');
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

Route::middleware(['auth', 'role:' . RoleNames::PROVIDER])->group(function () {
    Route::get('/provider/dashboard', [Provider\DashboardController::class, 'index'])->name('provider.dashboard');

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

    Route::middleware('account.active')->group(function () {
        Route::get('/opportunities/create', [OpportunityController::class, 'create'])->name('opportunities.create');
        Route::post('/opportunities/create', [OpportunityController::class, 'store'])->name('opportunities.store');
    });
});

/*
|--------------------------------------------------------------------------
| Administrators
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:' . RoleNames::ADMIN])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/analytics', [Admin\AnalyticsController::class, 'index'])->name('analytics');
    Route::get('/audit-log', [Admin\AuditLogController::class, 'index'])->name('audit');
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
    Route::post('/users/providers/{id}/reject', [Admin\UserController::class, 'rejectProvider'])
        ->whereNumber('id')
        ->name('providers.reject');
    Route::get('/providers/{userId}/certificate', [FileDownloadController::class, 'providerCertificate'])
        ->whereNumber('userId')
        ->name('providers.certificate');

    Route::post('/opportunities/{id}/approve', [Admin\ModerationController::class, 'approve'])
        ->whereNumber('id')
        ->name('moderation.approve');
    Route::post('/opportunities/{id}/reject', [Admin\ModerationController::class, 'reject'])
        ->whereNumber('id')
        ->name('moderation.reject');
});
