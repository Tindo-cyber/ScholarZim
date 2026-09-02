<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The three pages a freshly deployed instance has to serve before anyone has
 * signed up: the landing page, the public catalogue, and the health probe.
 *
 * Every other feature test seeds first, which is reasonable - they are about
 * behaviour that needs data to be about anything. The consequence is that
 * nothing covered the state a production database is actually in on the day it
 * goes live: migrations applied, and not one row of anything else. A landing
 * page that divides by a count, formats a null, or takes ->first()->title on an
 * empty table passes every existing test and 500s on the real deployment.
 *
 * So this file deliberately does NOT seed. RefreshDatabase gives it the
 * migrated, empty schema and nothing more.
 */
class PublicPagesOnEmptyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** The homepage, with no scholarships, no users, no applications. */
    public function test_the_landing_page_renders_on_a_completely_empty_database(): void
    {
        $this->assertDatabaseCount('opportunities', 0);
        $this->assertDatabaseCount('users', 0);

        $this->get('/')
            ->assertOk()
            ->assertSee('ScholarZim', false);
    }

    /** The public catalogue with nothing to list. */
    public function test_the_scholarships_page_renders_on_an_empty_database(): void
    {
        $this->get('/scholarships')->assertOk();
    }

    /** Liveness has no dependencies, so it must answer whatever the data says. */
    public function test_the_health_probe_answers_on_an_empty_database(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    /**
     * The statistics block is the part of the landing page most likely to break
     * on an empty database: five aggregates over five empty tables. Each must
     * come back as a real zero rather than null, because the view formats them.
     */
    public function test_the_public_statistics_are_zeroes_rather_than_nulls(): void
    {
        $stats = app(\App\Services\PlatformStatsService::class)->publicStats();

        foreach (['activeScholarships', 'students', 'providers', 'awardsMade', 'closingSoon'] as $key) {
            $this->assertArrayHasKey($key, $stats);
            $this->assertNotNull($stats[$key], "{$key} came back null on an empty database");
            $this->assertSame(0, (int) $stats[$key]);
        }
    }

    /** Featured listings on an empty catalogue: an empty collection, not a failure. */
    public function test_the_featured_listings_are_empty_rather_than_failing(): void
    {
        $featured = app(\App\Services\OpportunityService::class)->featured(6);

        $this->assertCount(0, $featured);
    }

    /**
     * The landing page is public, so every per-user list it builds is asked for
     * with a null user. Each has to answer for a guest rather than assume
     * somebody is signed in - the failure mode is a 500 on the one page that
     * every visitor sees first.
     */
    public function test_the_per_user_lists_answer_for_a_guest(): void
    {
        $this->assertCount(0, app(\App\Services\SavedScholarshipService::class)->savedIds(null));
        $this->assertCount(0, app(\App\Services\ApplicationService::class)->appliedIds(null));
        $this->assertCount(0, app(\App\Services\ApplicationService::class)->acceptedByOpportunity(null));
    }

    /**
     * Production warms config, routes and views at boot and runs with debug off,
     * so a failure there is a blank 500 rather than a stack trace. The pages
     * must render the same way with a cold cache as with a warm one - the
     * statistics are memoised for ten minutes, and the first visitor after a
     * deploy is the one who pays for building them.
     */
    public function test_the_landing_page_renders_with_a_cold_cache_and_then_a_warm_one(): void
    {
        Cache::flush();
        $this->get('/')->assertOk();

        // Second pass reads the memoised statistics rather than rebuilding them.
        $this->get('/')->assertOk();
    }

    /**
     * A guest hitting the catalogue with filters applied - the query string a
     * search engine or a shared link arrives with - must not need data to exist.
     */
    public function test_the_catalogue_accepts_filters_on_an_empty_database(): void
    {
        $this->get('/scholarships?keyword=engineering&level=Undergraduate&field=Engineering')
            ->assertOk();
    }
}
