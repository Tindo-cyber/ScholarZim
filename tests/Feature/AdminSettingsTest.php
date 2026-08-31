<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Admin-only controls: the ScholarFit weights, and moderating in bulk. */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    public function test_the_weights_page_renders_for_an_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/admin/scholarfit')->assertOk();
    }

    public function test_a_provider_cannot_reach_the_weights_page(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->get('/admin/scholarfit')->assertForbidden();
    }

    public function test_weights_are_saved_and_take_effect_immediately(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/scholarfit', [
                'weights' => [
                    'academic' => 30,
                    'education_level' => 20,
                    'field' => 20,
                    'location' => 15,
                    'deadline' => 10,
                    'certificate' => 5,
                ],
            ])
            ->assertRedirect(route('admin.scholarfit'));

        $this->assertSame(30, app(SettingsService::class)->scholarFitWeights()['academic']);
        $this->assertFalse(app(SettingsService::class)->scholarFitWeightsAreDefault());
    }

    /** Every score is shown as a percentage, so the weights have to total 100. */
    public function test_weights_that_do_not_total_one_hundred_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/scholarfit', [
                'weights' => [
                    'academic' => 50,
                    'education_level' => 50,
                    'field' => 50,
                    'location' => 15,
                    'deadline' => 10,
                    'certificate' => 5,
                ],
            ])
            ->assertSessionHasErrors('weights');

        $this->assertTrue(app(SettingsService::class)->scholarFitWeightsAreDefault());
    }

    /**
     * A criterion the engine does not score is refused rather than dropped.
     * Silently ignoring it meant an administrator could invent a dimension,
     * be told the weights saved, and have nothing change.
     */
    public function test_an_unknown_scoring_criterion_is_refused(): void
    {
        $settings = app(SettingsService::class);

        try {
            $settings->updateScholarFitWeights([
                'academic' => 20,
                'education_level' => 25,
                'field' => 25,
                'location' => 15,
                'deadline' => 10,
                'certificate' => 5,
                'interview_performance' => 0,
            ], $this->admin->email);

            $this->fail('an unknown criterion should not have been accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString(
                'interview_performance',
                implode(' ', $e->validator->errors()->all())
            );
        }

        $this->assertTrue($settings->scholarFitWeightsAreDefault());
    }

    /** The scoring identity names both the engine and the weights in force. */
    public function test_the_scoring_identity_names_the_engine_and_the_weights(): void
    {
        $settings = app(SettingsService::class);

        $this->assertStringContainsString('ScholarFit v2', $settings->scoringIdentity());
        $this->assertStringContainsString($settings->scoringVersion(), $settings->scoringIdentity());
    }

    public function test_resetting_restores_the_shipped_defaults(): void
    {
        $settings = app(SettingsService::class);
        $settings->updateScholarFitWeights([
            'academic' => 40,
            'education_level' => 20,
            'field' => 20,
            'location' => 10,
            'deadline' => 5,
            'certificate' => 5,
        ], $this->admin->email);

        $this->actingAs($this->admin)->post('/admin/scholarfit/reset')->assertRedirect();

        $this->assertSame(config('scholarfit.weights'), $settings->scholarFitWeights());
        $this->assertTrue($settings->scholarFitWeightsAreDefault());
    }

    public function test_bulk_approval_publishes_every_selected_listing(): void
    {
        $first = $this->pendingListing('Bulk Award One');
        $second = $this->pendingListing('Bulk Award Two');

        $this->actingAs($this->admin)
            ->post('/admin/opportunities/bulk-review', [
                'opportunities' => [$first->opportunity_id, $second->opportunity_id],
                'decision' => 'approve',
            ])
            ->assertRedirect();

        $this->assertSame(OpportunityModerationStatus::APPROVED, $first->fresh()->moderation_status);
        $this->assertSame(OpportunityModerationStatus::APPROVED, $second->fresh()->moderation_status);
    }

    /** A bulk decline is still a decline: the provider is shown a reason. */
    public function test_bulk_decline_requires_a_reason(): void
    {
        $listing = $this->pendingListing('Bulk Award Three');

        $this->actingAs($this->admin)
            ->post('/admin/opportunities/bulk-review', [
                'opportunities' => [$listing->opportunity_id],
                'decision' => 'reject',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->fresh()->moderation_status);
    }

    public function test_an_already_reviewed_listing_is_skipped_not_fatal(): void
    {
        $pending = $this->pendingListing('Bulk Award Four');
        $alreadyLive = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->actingAs($this->admin)
            ->post('/admin/opportunities/bulk-review', [
                'opportunities' => [$alreadyLive->opportunity_id, $pending->opportunity_id],
                'decision' => 'approve',
            ])
            ->assertRedirect()
            ->assertSessionHas('errorMessage');

        // The rest of the batch still went through.
        $this->assertSame(OpportunityModerationStatus::APPROVED, $pending->fresh()->moderation_status);
    }

    /** A prompt to look, never an automatic refusal. */
    public function test_the_moderation_preview_flags_a_likely_duplicate(): void
    {
        $original = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $copy = $this->pendingListing($original->title . ' 2026');

        $this->actingAs($this->admin)
            ->get('/admin/opportunities/' . $copy->opportunity_id)
            ->assertOk()
            ->assertSee('This may be a duplicate');
    }

    private function pendingListing(string $title): Opportunity
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        return Opportunity::create([
            'provider_user_id' => $provider->user_id,
            'provider_name' => $provider->full_name,
            'title' => $title,
            'description' => 'A listing awaiting review, created by the bulk moderation tests.',
            'education_level' => 'Undergraduate',
            'target_field' => 'Computer Science & IT',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::PENDING,
            'submitted_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);
    }
}
