<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Admin-only controls: moderating listings in bulk. */
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
