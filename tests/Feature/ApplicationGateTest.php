<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The education pathway rule enforced where it actually matters: a real
 * submission, over HTTP, through the same controller and service a student's
 * browser hits. EducationPathwayTest proves the table is correct in isolation;
 * this proves the table is actually wired into the gate that blocks a
 * submission, not merely advisory in a recommendation list.
 *
 * Everything here uses the seeded demo applicants precisely because they were
 * built to span this spectrum - see DatabaseSeeder's applicant methods.
 */
class ApplicationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_a_primary_pupil_can_apply_to_a_form_one_transition_award(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Chinhoyi Form 1 Transition Bursary')->firstOrFail();

        $this->actingAs($kudzai)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $kudzai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    public function test_a_primary_pupil_cannot_apply_to_an_undergraduate_award(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->actingAs($kudzai)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseMissing('applications', [
            'user_id' => $kudzai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    public function test_an_o_level_applicant_cannot_apply_to_a_phd_award(): void
    {
        $farai = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();

        $this->actingAs($farai)->post('/apply/' . $opportunity->opportunity_id . '/quick');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $farai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** The general pathway allows this; nothing about this specific listing narrows it further. */
    public function test_an_o_level_applicant_can_apply_to_an_undergraduate_award_that_accepts_o_level(): void
    {
        $farai = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->actingAs($farai)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $farai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /**
     * The general pathway (O-Level -> Undergraduate) is open, but this
     * specific listing has raised its own floor to A-Level - a provider rule,
     * not a fact about the education system. See Opportunity::minimum_education_level.
     */
    public function test_an_o_level_applicant_cannot_apply_where_the_listing_requires_at_least_a_level(): void
    {
        $farai = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Midlands Engineering Excellence Award')->firstOrFail();

        $this->actingAs($farai)->post('/apply/' . $opportunity->opportunity_id . '/quick');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $farai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** Tanaka exactly meets that same floor, so the identical listing accepts her. */
    public function test_an_a_level_applicant_can_apply_where_the_listing_requires_at_least_a_level(): void
    {
        $tanaka = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Midlands Engineering Excellence Award')->firstOrFail();

        $this->actingAs($tanaka)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $tanaka->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    public function test_an_a_level_applicant_cannot_apply_to_a_masters_award(): void
    {
        $tanaka = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Harare Health Sciences Postgraduate Grant')->firstOrFail();

        $this->actingAs($tanaka)->post('/apply/' . $opportunity->opportunity_id . '/quick');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $tanaka->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** A Masters applicant, with the transcript that listing's document rule asks for. */
    public function test_a_masters_applicant_can_apply_to_a_masters_award(): void
    {
        $blessing = User::where('email', 'blessing.moyana@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Harare Health Sciences Postgraduate Grant')->firstOrFail();

        $this->actingAs($blessing)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $blessing->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** Direct-to-service coverage: the gate is in ApplicationService, not merely the controller. */
    public function test_the_service_itself_refuses_a_pathway_violation_regardless_of_entry_point(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();

        $this->expectException(\RuntimeException::class);

        app(\App\Services\ApplicationService::class)->quickApply($opportunity->opportunity_id, $kudzai);
    }

    /** The wizard itself does not offer a submit button it knows will be refused. */
    public function test_the_wizard_does_not_show_a_submit_button_for_a_blocked_pathway(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();

        $response = $this->actingAs($kudzai)->get('/apply/' . $opportunity->opportunity_id);

        $response->assertOk();
        $response->assertSee('You cannot apply to this scholarship');
        $response->assertDontSee('Submit application');
    }
}
