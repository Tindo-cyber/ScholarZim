<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Features that were deliberately scoped out on 2026-08-31, when the project
 * was narrowed to its five official objectives: SMS, two-factor
 * authentication, saved-search alerts, bulk application decisions, an
 * "information requested" application status, and the public JSON API.
 *
 * These are not gaps to fill in. Each was cut on purpose, and the risk this
 * guards against is not "someone forgets to build it" but "someone rebuilds
 * it" - a future task described from a stale brief, a template that assumes
 * an earlier version of this project, or a well-meaning contributor who finds
 * the idea reasonable in isolation and does not know it was already tried and
 * removed.
 *
 * A test that asserts absence is unusual, but the alternative - relying on
 * institutional memory and a paragraph in a markdown file nobody re-reads
 * before writing code - is exactly how it would come back.
 */
class ScopeGuardTest extends TestCase
{
    /**
     * SMS was one of the features cut in that pass (commit 82827b2,
     * "Simplify to the five project objectives"). No SmsService, no SMS
     * configuration, no SMS-shaped notification type.
     */
    public function test_sms_is_not_part_of_the_notification_architecture(): void
    {
        $this->assertFalse(
            class_exists(\App\Services\SmsService::class),
            'SmsService exists again - confirm this is an intentional decision to reintroduce SMS, not a stale-brief rebuild'
        );

        $constants = (new \ReflectionClass(\App\Support\NotificationType::class))->getConstants();
        $smsLike = array_filter(array_keys($constants), fn (string $name) => str_contains(strtoupper($name), 'SMS'));

        $this->assertSame([], array_values($smsLike), 'a notification type mentions SMS, but no SMS delivery channel exists');
    }

    /** Two-factor authentication: removed alongside SMS in the same pass. */
    public function test_there_is_no_second_authentication_factor(): void
    {
        $this->assertSame(['web'], array_keys(config('auth.guards')), 'a second guard has appeared - two-factor auth was deliberately removed');
        $this->assertFalse(class_exists(\App\Services\TwoFactorService::class));
    }

    /** Saved-search alerts: removed in the same pass. */
    public function test_saved_search_alerts_do_not_exist(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('saved_searches') && \Illuminate\Support\Facades\Schema::hasColumn('saved_searches', 'last_notified_opportunity_id'));
        $this->assertFalse(class_exists(\App\Console\Commands\SendSavedSearchAlerts::class));
    }

    /**
     * The application workflow is exactly PENDING / ACCEPTED / REJECTED /
     * WITHDRAWN. "Information requested" and bulk decisions were both
     * removed - a decision is made on one application, by a person, with a
     * reason, never queued or batched.
     */
    public function test_the_application_workflow_has_no_intermediate_status(): void
    {
        $this->assertSame(
            ['ACCEPTED', 'REJECTED'],
            \App\Support\ApplicationStatus::DECISIONS,
            'a status beyond accept/reject has appeared - the workflow was deliberately reduced to these two'
        );

        $this->assertFalse(method_exists(\App\Services\ApplicationService::class, 'bulkDecide'));
        $this->assertFalse(method_exists(\App\Services\ApplicationService::class, 'requestInformation'));
    }

    /** The public JSON API was removed with its own Sanctum tokens and developer portal. */
    public function test_there_is_no_public_json_api(): void
    {
        $this->assertFalse(class_exists(\Laravel\Sanctum\SanctumServiceProvider::class));

        $apiRoutes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));

        $this->assertCount(0, $apiRoutes, 'a route under /api has appeared - the public API was deliberately removed');
    }
}
