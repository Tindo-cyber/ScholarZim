<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\AcademicRecord;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ScholarFitTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * What a student actually reads on /applicant/recommendations and on the scholarship page, for each of the four
 * eligibility states, using the accounts and listings from ScholarFitTestSeeder.
 *
 * Every page assertion is paired with what the evaluator says for the same student and listing, so the words on the
 * page can never drift from the evaluation behind them. The listings from the base seeder are switched off so the page
 * holds only the FIT ones (it also shows just the six closest refusals).
 */
class VisibleEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use GivesRequiredDocuments;

    private const NO_REQUIREMENTS = 'No specific requirements were provided. You appear eligible to apply.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFullCatalogue();
        $this->seed(DatabaseSeeder::class);
        $this->seed(ScholarFitTestSeeder::class);

        Opportunity::where('title', 'not like', 'FIT %')->update(['moderation_status' => OpportunityModerationStatus::PENDING]);
    }

    // ------------------------------------------------------------- helpers --

    private function user(string $who): User
    {
        return User::where('email', "fit.$who@scholarzim.co.zw")->firstOrFail();
    }

    private function listing(string $title): Opportunity
    {
        return Opportunity::where('title', "FIT $title")->firstOrFail();
    }

    private function recommendations(string $who): string
    {
        return $this->actingAs($this->user($who))->get('/applicant/recommendations')->assertOk()->getContent();
    }

    /** The one card for a listing on the page: from its title to the end of its <article>. */
    private function card(string $html, string $title): string
    {
        $at = strpos($html, "FIT $title");
        $this->assertNotFalse($at, "FIT $title is not on the page");

        $start = strrpos(substr($html, 0, $at), '<article');
        $end = strpos($html, '</article>', $at);

        return substr($html, $start, $end - $start);
    }

    /** @return array{matches: string, needs: string, refused: string} the page cut at its section headings */
    private function sections(string $html): array
    {
        $needs = (int) strpos($html, 'id="needs-information"');
        $refused = (int) strpos($html, "Scholarships you don't qualify for yet");

        $first = array_filter([$needs ?: null, $refused ?: null]);
        $matchesEnd = $first === [] ? strlen($html) : min($first);

        $bounds = [0, $matchesEnd];
        $needsEnd = $refused > $needs && $needs > 0 ? $refused : strlen($html);

        return [
            'matches' => substr($html, 0, $matchesEnd),
            'needs' => $needs > 0 ? substr($html, $needs, $needsEnd - $needs) : '',
            'refused' => $refused > 0 ? substr($html, $refused) : '',
        ];
    }

    private function fit(string $who, string $title)
    {
        return app(RecommendationService::class)->evaluateOne($this->user($who), $this->listing($title));
    }

    // ---------------------------------- 1. the no-requirements listing, end to end --

    public function test_open_doors_is_classified_eligible_by_the_engine(): void
    {
        $fit = $this->fit('rudo.mutasa', 'Open Doors Bursary');

        $this->assertFalse($fit->hasStatedRequirements());
        $this->assertTrue($fit->meetsRequirements());
        $this->assertTrue($fit->isEligibleWithoutRequirements());
        $this->assertFalse($fit->isIneligible());
        $this->assertFalse($fit->needsInformation());
    }

    public function test_the_recommendations_card_says_eligible_to_apply_and_offers_apply(): void
    {
        $html = $this->recommendations('rudo.mutasa');
        $card = $this->card($html, 'Open Doors Bursary');
        $matches = $this->sections($html)['matches'];

        $this->assertStringContainsString('FIT Open Doors Bursary', $matches, 'it is under "Matches for you"');
        $this->assertStringContainsString('Eligible', $card);
        $this->assertStringContainsString(self::NO_REQUIREMENTS, $card);
        $this->assertStringContainsString(route('applications.wizard', $this->listing('Open Doors Bursary')->opportunity_id), $card);
        $this->assertStringContainsString('>Apply</a>', $card);
    }

    public function test_the_card_tells_missing_provider_requirements_apart_from_requirements_the_student_met(): void
    {
        $html = $this->recommendations('rudo.mutasa');

        $open = $this->card($html, 'Open Doors Bursary');
        $met = $this->card($html, 'STEM Excellence Award');

        // Nothing was asked: no claim of anything met, no list of reasons to qualify.
        $this->assertStringContainsString(self::NO_REQUIREMENTS, $open);
        $this->assertStringNotContainsString('You meet', $open);
        $this->assertStringNotContainsString('Why you qualify', $open);

        // Two requirements were asked and met: said so, with the reasons one click away.
        $this->assertStringContainsString('You meet all 2 requirements this scholarship states.', $met);
        $this->assertStringContainsString('Why you qualify', $met);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required, you have 14.', $met);
        $this->assertStringNotContainsString(self::NO_REQUIREMENTS, $met);
    }

    public function test_the_scholarship_page_says_the_same_and_keeps_apply_available(): void
    {
        $listing = $this->listing('Open Doors Bursary');

        $html = $this->actingAs($this->user('rudo.mutasa'))->get(route('scholarships.show', $listing->opportunity_id))->assertOk()->getContent();

        $this->assertStringContainsString('ELIGIBLE', $html);
        $this->assertStringContainsString('No specific eligibility requirements have been provided by the scholarship provider. You appear eligible to apply based on the available information.', $html);
        $this->assertStringNotContainsString('You meet every requirement', $html);
        $this->assertStringContainsString('Apply now', $html);
        $this->assertStringContainsString(route('applications.wizard', $listing->opportunity_id), $html);
    }

    public function test_a_thin_profile_still_sees_open_doors_as_eligible_with_the_apply_action(): void
    {
        $html = $this->recommendations('tariro.gumbo');

        $this->assertStringContainsString('FIT Open Doors Bursary', $this->sections($html)['matches']);
        $this->assertStringContainsString(self::NO_REQUIREMENTS, $this->card($html, 'Open Doors Bursary'));
        $this->assertStringContainsString('>Apply</a>', $this->card($html, 'Open Doors Bursary'));
    }

    public function test_a_complete_profile_gets_the_application_form_for_open_doors(): void
    {
        $id = $this->listing('Open Doors Bursary')->opportunity_id;
        $this->giveRequiredDocuments($this->user('rudo.mutasa'));

        $this->actingAs($this->user('rudo.mutasa'))->get(route('applications.wizard', $id))->assertOk()->assertSee('Submit application');
    }

    public function test_a_thin_school_leaver_profile_is_eligible_but_meets_the_guardian_gate_before_the_form(): void
    {
        $id = $this->listing('Open Doors Bursary')->opportunity_id;

        // No date of birth at school level is read as a minor (ApplicantProfile::isMinor), so a guardian comes first.
        $this->actingAs($this->user('tariro.gumbo'))->get(route('applications.wizard', $id))->assertOk()
            ->assertSee('Parent or guardian')
            ->assertSee('name="profile[guardian_name]"', false)
            ->assertDontSee('Submit application');
    }

    public function test_an_incomplete_adult_profile_is_eligible_but_must_be_completed_before_the_form(): void
    {
        $id = $this->listing('Open Doors Bursary')->opportunity_id;
        ApplicantProfile::where('user_id', $this->user('lloyd.ncube')->user_id)->update(['biography' => null]);

        $this->assertTrue($this->fit('lloyd.ncube', 'Open Doors Bursary')->isEligibleWithoutRequirements(), 'eligible regardless');

        $this->actingAs($this->user('lloyd.ncube'))->get(route('applications.wizard', $id))->assertOk()
            ->assertSee('A few details are still needed')
            ->assertSee('name="profile[biography]"', false)
            ->assertDontSee('Submit application');
    }

    // ------------------------------- missing requirements never override restrictions --

    public function test_an_expired_listing_with_no_requirements_is_not_offered_or_applyable(): void
    {
        $expired = $this->listing('Expired Merit Award');

        $this->assertStringNotContainsString('FIT Expired Merit Award', $this->recommendations('rudo.mutasa'));
        $this->get(route('scholarships.show', $expired->opportunity_id))->assertNotFound();
        $this->actingAs($this->user('rudo.mutasa'))->get(route('applications.wizard', $expired->opportunity_id))->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedStates')]
    public function test_an_inactive_listing_with_no_requirements_is_not_offered_or_applyable(array $state): void
    {
        $open = $this->listing('Open Doors Bursary');
        $copy = $open->replicate();
        $copy->forceFill(array_merge(['title' => 'FIT Closed Copy'], $state))->save();

        $this->assertStringNotContainsString('FIT Closed Copy', $this->recommendations('rudo.mutasa'));
        $this->actingAs($this->user('rudo.mutasa'))->get(route('applications.wizard', $copy->opportunity_id))->assertNotFound();
        $this->actingAs($this->user('rudo.mutasa'))->get(route('scholarships.show', $copy->opportunity_id))->assertNotFound();
    }

    public static function closedStates(): array
    {
        return [
            'closed' => [['status' => OpportunityStatus::CLOSED]],
            'withdrawn' => [['status' => OpportunityStatus::WITHDRAWN]],
            'not approved' => [['moderation_status' => OpportunityModerationStatus::PENDING]],
            // Two days back: this runs before the application sets its zone, so "yesterday" could still be today in UTC.
            'past its deadline' => [['deadline' => Carbon::now('UTC')->subDays(2)->toDateString()]],
        ];
    }

    public function test_an_explicitly_required_qualification_level_still_refuses_on_the_page(): void
    {
        // "Bachelor Completion Bursary" asks for a Diploma first; Rudo has finished A-Level only.
        $html = $this->recommendations('rudo.mutasa');
        $card = $this->card($html, 'Bachelor Completion Bursary');

        $this->assertStringContainsString("Scholarships you don't qualify for yet", $html);
        $this->assertStringContainsString('NOT ELIGIBLE', $card);
        $this->assertStringContainsString('Diploma', $card);
        $this->assertStringNotContainsString('>Apply</a>', $card);
        $this->assertTrue($this->fit('rudo.mutasa', 'Bachelor Completion Bursary')->isIneligible());
    }

    // ------------------------------------------------- 2. the four visible states --

    public function test_eligible_the_requirements_were_configured_and_met(): void
    {
        $html = $this->recommendations('rudo.mutasa');

        $this->assertStringContainsString('FIT STEM Excellence Award', $this->sections($html)['matches']);
        $this->assertStringContainsString('You meet all 2 requirements', $this->card($html, 'STEM Excellence Award'));
        $this->assertTrue($this->fit('rudo.mutasa', 'STEM Excellence Award')->meetsRequirements());
        $this->assertTrue($this->fit('rudo.mutasa', 'STEM Excellence Award')->hasStatedRequirements());
    }

    public function test_ineligible_a_mandatory_requirement_failed_with_the_real_figures(): void
    {
        // The page shows only the six closest refusals; leave this one listing and the open one on it.
        Opportunity::where('title', 'like', 'FIT %')->whereNotIn('title', ['FIT STEM Excellence Award', 'FIT Open Doors Bursary'])
            ->update(['moderation_status' => OpportunityModerationStatus::PENDING]);

        $html = $this->recommendations('simba.dube');
        $sections = $this->sections($html);
        $card = $this->card($html, 'STEM Excellence Award');

        $this->assertStringNotContainsString('FIT STEM Excellence Award', $sections['matches']);
        $this->assertStringContainsString('FIT STEM Excellence Award', $sections['refused']);
        $this->assertStringContainsString('NOT ELIGIBLE', $card);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required, you have 4.', $card);
        $this->assertStringContainsString('Mathematics: B required, you have D.', $card);
        $this->assertTrue($this->fit('simba.dube', 'STEM Excellence Award')->isIneligible());
    }

    public function test_incomplete_information_is_asked_for_not_refused(): void
    {
        $html = $this->recommendations('tariro.gumbo');
        $sections = $this->sections($html);

        $this->assertStringContainsString('Complete your profile to check', $html);
        $this->assertStringContainsString('FIT STEM Excellence Award', $sections['needs']);
        $this->assertStringContainsString('ZIMSEC Advanced Level points: 12 required - add your', $sections['needs']);
        $this->assertStringContainsString('FIT Manicaland Rural Learners Fund', $sections['needs']);
        $this->assertStringContainsString('Province: Manicaland required - add your province', $sections['needs']);
        $this->assertStringNotContainsString('FIT STEM Excellence Award', $sections['matches']);
        $this->assertStringNotContainsString('FIT STEM Excellence Award', $sections['refused']);
        $this->assertTrue($this->fit('tariro.gumbo', 'STEM Excellence Award')->needsInformation());
        $this->assertFalse($this->fit('tariro.gumbo', 'STEM Excellence Award')->isIneligible());
    }

    public function test_no_requirements_configured_reads_as_eligible_to_apply_not_as_a_met_requirement(): void
    {
        $html = $this->recommendations('nyasha.hove');
        $card = $this->card($html, 'Open Doors Bursary');

        $this->assertStringContainsString('Eligible', $card);
        $this->assertStringContainsString(self::NO_REQUIREMENTS, $card);
        $this->assertStringNotContainsString('You meet', $card);
        $this->assertTrue($this->fit('nyasha.hove', 'Open Doors Bursary')->isEligibleWithoutRequirements());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('students')]
    public function test_every_reason_on_the_page_comes_from_the_evaluation_behind_it(string $who): void
    {
        $html = $this->recommendations($who);
        $refused = $this->sections($html)['refused'];
        $checked = 0;

        foreach (Opportunity::where('title', 'like', 'FIT %')->publiclyVisible()->get() as $listing) {
            $fit = app(RecommendationService::class)->evaluateOne($this->user($who), $listing);

            if (! $fit->isIneligible() || ! str_contains($refused, $listing->title)) {
                continue;
            }

            $card = html_entity_decode($this->card($html, substr($listing->title, 4)), ENT_QUOTES);

            foreach ($fit->failureMessages() as $message) {
                $this->assertStringContainsString($message, $card, "$who / {$listing->title}");
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, "$who has refusals on the page to compare");
    }

    public static function students(): array
    {
        return ['simba' => ['simba.dube'], 'fadzai' => ['fadzai.moyo'], 'rudo' => ['rudo.mutasa'], 'joseph' => ['joseph.banda'], 'tawanda' => ['tawanda.zhou']];
    }

    // ----------------------- 3. a degree classification is not proof of a degree --

    private function setLevel(string $who, string $level, ?string $classification): ApplicantProfile
    {
        $profile = ApplicantProfile::where('user_id', $this->user($who)->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => $level, 'degree_classification' => $classification])->save();

        return $profile->refresh();
    }

    public function test_a_diploma_holder_with_a_classification_is_not_read_as_holding_a_degree(): void
    {
        $profile = $this->setLevel('memory.chikwanha', EducationLevel::DIPLOMA, 'Lower Second (2:2)');

        $this->assertNotContains(EducationLevel::UNDERGRADUATE, AcademicRecord::fromProfile($profile)->levelsHeld());

        // The Undergraduate award that asks for a Diploma first is still open to her.
        $this->assertTrue($this->fit('memory.chikwanha', 'Bachelor Completion Bursary')->meetsRequirements());
        $this->assertFalse($this->fit('memory.chikwanha', 'Bachelor Completion Bursary')->isIneligible());
        $this->assertSame('Lower Second (2:2)', $profile->degree_classification, 'the stored value is untouched');
    }

    public function test_a_certificate_holder_with_a_classification_is_not_read_as_holding_a_degree(): void
    {
        $profile = $this->setLevel('memory.chikwanha', EducationLevel::CERTIFICATE, 'Pass');

        $this->assertNotContains(EducationLevel::UNDERGRADUATE, AcademicRecord::fromProfile($profile)->levelsHeld());
    }

    public function test_an_undergraduate_graduate_with_a_classification_still_counts_as_holding_the_degree(): void
    {
        $profile = $this->setLevel('tawanda.zhou', EducationLevel::UNDERGRADUATE, 'Upper Second (2:1)');

        $this->assertContains(EducationLevel::UNDERGRADUATE, AcademicRecord::fromProfile($profile)->levelsHeld());

        $refused = $this->fit('tawanda.zhou', 'Young Innovators Under-22 Grant');
        $this->assertTrue($refused->isIneligible());
        $this->assertStringContainsString('already finished Undergraduate', implode(' ', $refused->failureMessages()));
        $this->assertTrue($this->fit('tawanda.zhou', 'Postgraduate Computing Fellowship')->meetsRequirements(), 'Masters is the next step');
    }

    public function test_a_masters_graduate_with_a_classification_holds_the_degree_too(): void
    {
        $profile = $this->setLevel('lloyd.ncube', EducationLevel::MASTERS, 'First Class');

        $this->assertContains(EducationLevel::UNDERGRADUATE, AcademicRecord::fromProfile($profile)->levelsHeld());
    }

    public function test_a_diploma_holder_with_no_academic_information_is_not_refused_for_it(): void
    {
        $profile = $this->setLevel('memory.chikwanha', EducationLevel::DIPLOMA, null);
        $profile->academicResults()->delete();

        // A Diploma is shown by its documents: no academic information is needed for the profile to be complete.
        $this->assertTrue($profile->refresh()->isComplete(), 'a Diploma needs no academic information');
        $this->assertSame([], AcademicRecord::fromProfile($profile)->levelsHeld());
        $this->assertFalse($this->fit('memory.chikwanha', 'Bachelor Completion Bursary')->isIneligible(), 'incomplete is not ineligible');
        $this->assertTrue($this->fit('memory.chikwanha', 'Bachelor Completion Bursary')->meetsRequirements());
    }

    public function test_a_profile_with_no_stated_level_and_a_classification_is_asked_for_its_level_not_refused(): void
    {
        $profile = ApplicantProfile::where('user_id', $this->user('memory.chikwanha')->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => null, 'degree_classification' => 'Pass'])->save();

        $fit = $this->fit('memory.chikwanha', 'Young Innovators Under-22 Grant');

        $this->assertFalse($fit->isIneligible() && str_contains(implode(' ', $fit->failureMessages()), 'already finished'));
        $this->assertTrue($fit->needsInformation() || $fit->isIneligible());
        $this->assertContains('education level', array_map('strtolower', $fit->missingFields()));
    }

    // ------------------------------------------------ 4. programme-level restrictions --

    public function test_a_listing_at_another_level_than_a_school_leavers_programmes_is_outside_their_choices_not_a_refusal(): void
    {
        $html = $this->recommendations('rudo.mutasa');
        $card = $this->card($html, 'Diploma Skills Award');

        $this->assertStringNotContainsString('NOT ELIGIBLE', $card);
        $this->assertStringContainsString('Outside your chosen programmes', $card);
        $this->assertStringContainsString('this scholarship funds Diploma study, but the programmes you chose are at Undergraduate level', $card);
        $this->assertStringContainsString('BSc Computer Science', $card, 'it names the programmes it compared');
        $this->assertStringContainsString('does not rule you out', $card);
        $this->assertTrue($this->fit('rudo.mutasa', 'Diploma Skills Award')->meetsRequirements());
    }

    public function test_choosing_a_programme_at_that_level_moves_it_from_outside_their_choices_to_a_match(): void
    {
        $profile = ApplicantProfile::where('user_id', $this->user('rudo.mutasa')->user_id)->firstOrFail();
        $this->assertTrue($this->fit('rudo.mutasa', 'Diploma Skills Award')->outsidePreferences());

        ApplicantProgramme::create([
            'profile_id' => $profile->profile_id,
            'programme_id' => Programme::where('name', 'Diploma in Information Technology')->value('id'),
            'kind' => ApplicantProgramme::INTENDED,
        ]);

        $fit = $this->fit('rudo.mutasa', 'Diploma Skills Award');
        $this->assertTrue($fit->meetsRequirements());
        $this->assertFalse($fit->outsidePreferences());
        $this->assertStringContainsString('FIT Diploma Skills Award', $this->sections($this->recommendations('rudo.mutasa'))['matches']);
    }

    public function test_a_listing_that_names_no_level_invents_no_programme_level_restriction(): void
    {
        // Open Doors states no level at all, and Rudo's programmes are all Undergraduate: nothing to refuse.
        $fit = $this->fit('rudo.mutasa', 'Open Doors Bursary');

        $this->assertFalse($fit->isIneligible());
        $this->assertSame([], $fit->failureMessages());
    }

    public function test_the_intended_programme_level_rule_does_not_apply_to_someone_already_on_a_programme(): void
    {
        // Tawanda has a current programme, not intended ones: the Masters award is judged on the ladder, not on his programme's level.
        $this->assertTrue($this->fit('tawanda.zhou', 'Postgraduate Computing Fellowship')->meetsRequirements());
    }

    public function test_the_page_never_shows_a_score_or_a_percentage(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}\s*%\s*(match|fit|compatib)/i', $this->recommendations('rudo.mutasa'));
    }
}
