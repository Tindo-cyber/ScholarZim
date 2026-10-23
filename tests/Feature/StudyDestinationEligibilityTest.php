<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * Applying for an award to be studied at a particular university.
 *
 * Tanaka is an A-Level leaver at Prince Edward School who has not enrolled anywhere. A scholarship for an undergraduate
 * programme at MSU names MSU as where it is studied; that is not a condition on where he is now. What he is, and what
 * the listing really asks (level, results, programme where one is named), decides - separately.
 */
class StudyDestinationEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use GivesRequiredDocuments;

    private User $leaver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->leaver = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function listing(array $attributes = [], array $scopes = []): Opportunity
    {
        $listing = Opportunity::create($attributes + [
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'), 'provider_name' => 'Destination Provider',
            'title' => 'MSU Destination Award', 'description' => 'For study at Midlands State University.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'open_to_all_programmes' => $scopes === [], 'any_institution' => false,
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => Institution::where('code', 'MSU')->value('id')]);

        foreach ($scopes as $column => $id) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, $column => $id]);
        }

        return $listing->fresh();
    }

    private function apply(Opportunity $listing, ?User $who = null)
    {
        $who ??= $this->leaver;
        $this->giveRequiredDocuments($who, $listing);
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($who)->post('/apply/' . $listing->opportunity_id . '/quick');
    }

    public function test_an_a_level_student_who_is_not_at_msu_can_apply_for_an_award_to_be_studied_there(): void
    {
        $this->assertNotSame('Midlands State University', $this->leaver->applicantProfile->institution_name);
        $listing = $this->listing();

        $this->apply($listing)->assertSessionHasNoErrors();

        $this->assertSame(1, Application::where('user_id', $this->leaver->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_a_blank_or_different_current_institution_never_disqualifies(): void
    {
        $listing = $this->listing();

        foreach ([null, '', 'Gweru Girls High School', 'University of Zimbabwe'] as $named) {
            $this->leaver->applicantProfile->forceFill(['institution_name' => $named])->save();
            $fit = app(RecommendationService::class)->evaluateOne($this->leaver->fresh(), $listing);

            $this->assertFalse($fit->isIneligible(), (string) $named);
            $this->assertFalse($fit->needsInformation(), 'and they are not asked where they study');
        }
    }

    public function test_the_award_is_recommended_and_says_where_it_is_studied(): void
    {
        $listing = $this->listing();

        $ids = array_map(fn ($r) => $r->opportunity->opportunity_id, app(RecommendationService::class)->forUser($this->leaver->fresh(), 0));
        $this->assertContains($listing->opportunity_id, $ids);

        $this->actingAs($this->leaver)->get('/scholarships/' . $listing->opportunity_id)->assertOk()->assertSee('study at MSU');
    }

    public function test_the_application_check_agrees_with_the_recommendation(): void
    {
        $listing = $this->listing(['title' => 'Needs A Qualification He Lacks', 'minimum_education_level' => EducationLevel::UNDERGRADUATE]);

        $this->assertFalse(app(RecommendationService::class)->evaluateOne($this->leaver->fresh(), $listing)->meetsRequirements());
        $this->apply($listing)->assertSessionHas('errorMessage');
        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count(), 'a real requirement still stops the application');
    }

    public function test_a_programme_the_award_names_is_still_a_real_restriction_for_an_enrolled_student(): void
    {
        $student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $profile = $student->applicantProfile;
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE])->save();
        $listing = $this->listing(['education_level' => EducationLevel::MASTERS], ['programme_id' => Programme::where('name', 'BSc Computer Science')->value('id')]);

        $enrol = fn (string $programme, string $university) => \App\Models\ApplicantProgramme::query()->where('profile_id', $profile->profile_id)->delete()
            ?: \App\Models\ApplicantProgramme::create([
                'profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', $programme)->value('id'),
                'kind' => 'current', 'institution_id' => Institution::where('code', $university)->value('id'),
            ]);

        $enrol('Bachelor of Laws', 'MSU');
        $atMsuWrongProgramme = app(RecommendationService::class)->evaluateOne($student->fresh(), $listing);
        $this->assertTrue($atMsuWrongProgramme->isIneligible(), 'at the intended university, but not on the programme: the programme is the restriction');

        $enrol('BSc Computer Science', 'UZ');
        $rightProgrammeElsewhere = app(RecommendationService::class)->evaluateOne($student->fresh(), $listing);
        $this->assertFalse($rightProgrammeElsewhere->isIneligible(), 'on the programme but at another university: the university is only where it is studied');
    }

    public function test_the_institution_never_changes_what_an_older_level_requirement_says(): void
    {
        // An O-Level leaver for a degree award that does not take O-Level holders is refused for the LEVEL, with or
        // without a university on the listing.
        $this->leaver->applicantProfile->forceFill(['education_level' => EducationLevel::O_LEVEL])->save();

        $withInstitution = $this->listing();
        $without = $this->listing(['title' => 'No Destination Award']);
        OpportunityScope::where('opportunity_id', $without->opportunity_id)->delete();
        $without->forceFill(['any_institution' => true])->save();

        $a = app(RecommendationService::class)->evaluateOne($this->leaver->fresh(), $withInstitution->fresh());
        $b = app(RecommendationService::class)->evaluateOne($this->leaver->fresh(), $without->fresh());

        $this->assertSame($b->isIneligible(), $a->isIneligible());
        $this->assertSame($b->failureMessages(), $a->failureMessages());
    }
}
