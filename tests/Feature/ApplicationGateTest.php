<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What actually gates a submission, exercised over HTTP through the same
 * controller and service a student's browser hits.
 *
 * The rule under test is the listing's own stated requirements - not a table of
 * which level usually follows which. A scholarship that asks for A-Level turns
 * away an applicant without one; a scholarship that asks for nothing turns away
 * nobody, however unusual the step they are taking. EducationPathway is
 * advisory now and appears here only as a note.
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

    /**
     * An unusual step is not a refusal. This listing states no entry
     * requirement, so nothing refuses a Primary pupil - they are told the
     * progression is unusual and left to decide.
     *
     * This previously asserted the opposite, on the strength of the pathway
     * table alone. That was the assumption the product no longer makes: a
     * level does not imply its destinations, and only the provider can say who
     * their award is for.
     */
    public function test_a_primary_pupil_is_not_refused_by_a_listing_that_states_no_requirement(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();

        $this->assertNull($opportunity->minimum_education_level, 'fixture must state no minimum');

        $this->actingAs($kudzai)
            ->post('/apply/' . $opportunity->opportunity_id . '/quick')
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $kudzai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** The same pupil and the same target, once the listing does state a requirement. */
    public function test_a_primary_pupil_is_refused_once_the_listing_requires_a_level(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Undergraduate Bursary')->firstOrFail();
        $opportunity->forceFill(['minimum_education_level' => \App\Support\EducationLevel::A_LEVEL])->save();

        $this->actingAs($kudzai)->post('/apply/' . $opportunity->opportunity_id . '/quick');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $kudzai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /**
     * A research grant that asks for nothing refuses nobody. The applicant is
     * told this is not a usual next step, and the provider - who alone knows
     * who the award is for - can state a requirement if it is not open to them.
     */
    public function test_an_o_level_applicant_is_told_a_phd_award_is_an_unusual_step_not_refused(): void
    {
        $farai = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();

        $fit = app(\App\Services\RecommendationService::class)->scoreOne($farai, $opportunity);

        $this->assertTrue($fit->meetsRequirements(), 'nothing stated, nothing refused');

        $notes = array_map(fn ($n) => $n->message, $fit->breakdown->advisoryNotes());
        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('not a usual next step', implode(' ', $notes));

        // And it ranks poorly on the education dimension rather than being hidden.
        $education = $fit->breakdown->dimension('education');
        $this->assertNotNull($education);
        $this->assertSame(0, $education->points());
    }

    public function test_an_o_level_applicant_is_refused_a_phd_award_that_requires_a_degree(): void
    {
        $farai = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();
        $opportunity->forceFill(['minimum_education_level' => \App\Support\EducationLevel::MASTERS])->save();

        $this->actingAs($farai)->post('/apply/' . $opportunity->opportunity_id . '/quick');

        $this->assertDatabaseMissing('applications', [
            'user_id' => $farai->user_id,
            'opportunity_id' => $opportunity->opportunity_id,
        ]);
    }

    /** The general pathway allows O-Level -> Undergraduate; without an explicit
     * minimum floor the applicant is eligible. A separate listing-level minimum
     * can still turn them away. */
    public function test_an_o_level_applicant_can_apply_to_an_undergraduate_award_without_a_minimum(): void
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

    /** Stated requirements decide it: this listing asks for a degree, and she has none recorded. */
    public function test_an_a_level_applicant_is_refused_a_masters_award_that_requires_a_degree(): void
    {
        $tanaka = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Harare Health Sciences Postgraduate Grant')->firstOrFail();
        $opportunity->forceFill(['minimum_education_level' => \App\Support\EducationLevel::UNDERGRADUATE])->save();

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
    public function test_the_service_itself_refuses_a_stated_requirement_regardless_of_entry_point(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();
        $opportunity->forceFill(['minimum_education_level' => \App\Support\EducationLevel::MASTERS])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Masters required');

        app(\App\Services\ApplicationService::class)->quickApply($opportunity->opportunity_id, $kudzai);
    }

    /** The wizard itself does not offer a submit button it knows will be refused. */
    public function test_the_wizard_does_not_show_a_submit_button_for_an_unmet_requirement(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $opportunity = Opportunity::where('title', 'Agribusiness Innovation Research Grant')->firstOrFail();
        $opportunity->forceFill(['minimum_education_level' => \App\Support\EducationLevel::MASTERS])->save();

        $response = $this->actingAs($kudzai)->get('/apply/' . $opportunity->opportunity_id);

        $response->assertOk();
        $response->assertSee('NOT ELIGIBLE');
        $response->assertDontSee('Submit application');
    }
}
