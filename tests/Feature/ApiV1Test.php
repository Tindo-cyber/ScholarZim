<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The versioned JSON API and the token auth in front of the /me half of it. */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
    }

    public function test_the_catalogue_is_open_and_paginated(): void
    {
        $this->getJson('/api/v1/scholarships?per_page=2')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'award' => ['amount', 'currency'], 'eligibility']],
                'meta' => ['sort'],
            ])
            ->assertJsonCount(2, 'data');
    }

    public function test_the_catalogue_only_returns_what_the_public_site_shows(): void
    {
        $pending = Opportunity::where('title', 'Bulawayo Mining Skills Scholarship')->firstOrFail();

        $this->getJson('/api/v1/scholarships')
            ->assertOk()
            ->assertJsonMissing(['title' => $pending->title]);

        $this->getJson('/api/v1/scholarships/' . $pending->opportunity_id)->assertNotFound();
    }

    public function test_an_unknown_sort_falls_back_rather_than_erroring(): void
    {
        $this->getJson('/api/v1/scholarships?sort=; drop table users')
            ->assertOk()
            ->assertJsonPath('meta.sort', 'newest');
    }

    public function test_facets_and_stats_are_public(): void
    {
        $this->getJson('/api/v1/facets')
            ->assertOk()
            ->assertJsonStructure(['educationLevels', 'fieldsOfStudy', 'sorts']);

        $this->getJson('/api/v1/stats')->assertOk();
    }

    public function test_the_openapi_document_is_served_and_names_this_deployment(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('servers.0.url', url('/api/v1'));
    }

    public function test_the_me_endpoints_refuse_an_anonymous_caller(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me/recommendations')->assertUnauthorized();
    }

    public function test_a_bearer_token_reaches_the_applicants_own_data(): void
    {
        $token = $this->student->createToken('test-token', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email', $this->student->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me/recommendations?limit=3')
            ->assertOk()
            ->assertJsonStructure(['data' => [['matchScore', 'confidence', 'scholarship' => ['id', 'title']]]]);
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $token = $this->student->createToken('short-lived', ['read']);
        $plain = $token->plainTextToken;

        $token->accessToken->delete();

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    /** The paths that existed before versioning still answer. */
    public function test_the_unversioned_aliases_still_work(): void
    {
        $this->getJson('/api/public/scholarships')->assertOk();
        $this->getJson('/api/public/stats')->assertOk();
    }

    public function test_a_provider_gets_no_recommendations(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $token = $provider->createToken('provider-token', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/me/recommendations')
            ->assertForbidden();
    }
}
