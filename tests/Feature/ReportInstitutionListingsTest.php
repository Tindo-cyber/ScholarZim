<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\User;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The read-only report of listings that name institutions: it lists them and changes nothing. */
class ReportInstitutionListingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_listings_that_name_institutions_and_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $listing = Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'), 'provider_name' => 'Report Provider',
            'title' => 'Report Institution Award', 'description' => 'Names MSU.', 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'any_institution' => false, 'open_to_all_programmes' => true,
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED, 'created_at' => now(),
        ]);
        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => Institution::where('code', 'MSU')->value('id')]);
        $before = Opportunity::query()->orderBy('opportunity_id')->get()->map->getAttributes()->all();
        $scopes = OpportunityScope::count();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('scholarzim:report-institution-listings'));
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('Report Institution Award', $output);
        $this->assertStringContainsString('Midlands State University', $output);
        $this->assertStringContainsString('nothing was changed', $output);

        $this->assertSame($before, Opportunity::query()->orderBy('opportunity_id')->get()->map->getAttributes()->all());
        $this->assertSame($scopes, OpportunityScope::count());
    }
}
