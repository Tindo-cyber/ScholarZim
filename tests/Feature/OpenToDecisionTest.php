<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantTargetField;
use App\Models\Field;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Who is it open to?" is a decision a provider must make at Certificate level and above, because ScholarFit
 * uses it to tell each student whether they qualify. Study abroad is open to fields only. Old listings that
 * never answered keep working and their provider is asked to answer. And a graduate can name fields to study next.
 */
class OpenToDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // This class is about the answer itself, so nothing fills it in.
        $this->withoutOpenToAnswer();
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'title' => 'Open To Award',
            'description' => 'A listing used to exercise the open-to decision.',
            'education_level' => EducationLevel::UNDERGRADUATE,
            'country' => 'Zimbabwe',
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->provider)->post('/opportunities/create', $this->form($extra));
    }

    private function saved(): ?Opportunity
    {
        return Opportunity::with('scopes')->where('title', 'Open To Award')->first();
    }

    private function programme(string $name): int
    {
        return Programme::where('name', $name)->value('id');
    }

    private function field(string $code): int
    {
        return Field::where('code', $code)->value('id');
    }

    private function institution(string $code): int
    {
        return Institution::where('code', $code)->value('id');
    }

    // ------------------------------------------------------------- required --

    public function test_a_degree_award_cannot_be_posted_without_saying_who_it_is_open_to(): void
    {
        $this->create()->assertSessionHasErrors(['open_to', 'institutions_open_to']);

        $this->assertNull($this->saved());
    }

    public function test_all_programmes_and_any_institution_is_an_answer(): void
    {
        $this->create(['open_to' => 'all', 'institutions_open_to' => 'any'])->assertSessionHasNoErrors();

        $listing = $this->saved();
        $this->assertTrue($listing->open_to_all_programmes);
        $this->assertTrue($listing->any_institution);
        $this->assertCount(0, $listing->scopes);
    }

    public function test_choosing_from_the_lists_answers_the_question_without_the_radio(): void
    {
        $this->create([
            'scope_programmes' => [$this->programme('BSc Computer Science')],
            'scope_institutions' => [$this->institution('MSU')],
        ])->assertSessionHasNoErrors();

        $listing = $this->saved();
        $this->assertFalse($listing->open_to_all_programmes);
        $this->assertFalse($listing->any_institution);
        $this->assertCount(2, $listing->scopes);
    }

    public function test_only_the_ones_i_choose_needs_at_least_one(): void
    {
        $this->create(['open_to' => 'chosen', 'institutions_open_to' => 'chosen'])
            ->assertSessionHasErrors(['open_to', 'institutions_open_to']);
    }

    public function test_all_programmes_drops_a_list_left_behind(): void
    {
        $this->create([
            'open_to' => 'all', 'institutions_open_to' => 'any',
            'scope_fields' => [$this->field('061')],
            'scope_institutions' => [$this->institution('MSU')],
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->saved()->scopes, 'the answer is the rule, not the list beside it');
    }

    public function test_a_school_level_award_is_not_asked(): void
    {
        $this->create(['education_level' => EducationLevel::A_LEVEL])->assertSessionHasNoErrors();

        $this->assertNull($this->saved()->open_to_all_programmes);
    }

    public function test_a_draft_may_leave_it_unanswered(): void
    {
        $this->actingAs($this->provider)->post('/opportunities/draft', $this->form())->assertSessionHasNoErrors();

        $this->assertNotNull(Opportunity::where('title', 'Open To Award')->first());
    }

    // ------------------------------------------------------------- abroad --

    public function test_abroad_a_zimbabwean_programme_or_institution_is_refused(): void
    {
        $this->create([
            'country' => 'Germany',
            'scope_programmes' => [$this->programme('BSc Computer Science')],
            'scope_institutions' => [$this->institution('MSU')],
        ])->assertSessionHasErrors(['scope_programmes', 'scope_institutions']);
    }

    public function test_abroad_fields_are_the_answer_and_institutions_are_not_asked(): void
    {
        $this->create([
            'country' => 'Germany',
            'education_level' => EducationLevel::MASTERS,
            'scope_fields' => [$this->field('061')],
            'host_institutions' => 'A partner university in Germany',
        ])->assertSessionHasNoErrors();

        $listing = $this->saved();
        $this->assertFalse($listing->open_to_all_programmes);
        $this->assertNull($listing->any_institution);
        $this->assertSame('A partner university in Germany', $listing->host_institutions);
        $this->assertSame([$this->field('061')], $listing->scopes->pluck('field_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_host_universities_are_only_kept_for_study_abroad(): void
    {
        $this->create(['open_to' => 'all', 'institutions_open_to' => 'any', 'host_institutions' => 'Somewhere'])->assertSessionHasNoErrors();

        $this->assertNull($this->saved()->host_institutions);
    }

    // -------------------------------------------------- O-Level and conditions --

    public function test_the_o_level_tick_is_kept_for_a_degree_award_only(): void
    {
        $this->create(['open_to' => 'all', 'institutions_open_to' => 'any', 'accepts_o_level' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->saved()->accepts_o_level);

        $this->actingAs($this->provider)->post('/opportunities/create', $this->form([
            'title' => 'Diploma Award', 'education_level' => EducationLevel::DIPLOMA,
            'open_to' => 'all', 'institutions_open_to' => 'any', 'accepts_o_level' => '1',
        ]))->assertSessionHasNoErrors();
        $this->assertFalse(Opportunity::where('title', 'Diploma Award')->first()->accepts_o_level);
    }

    public function test_other_conditions_are_kept_one_a_line(): void
    {
        $this->create([
            'open_to' => 'all', 'institutions_open_to' => 'any',
            'other_conditions' => "- IELTS 6.5 or higher\n\n* Two years of work experience\nIELTS 6.5 or higher",
        ])->assertSessionHasNoErrors();

        $this->assertSame("IELTS 6.5 or higher\nTwo years of work experience", $this->saved()->other_conditions);
    }

    public function test_more_than_ten_conditions_are_refused(): void
    {
        $this->create([
            'open_to' => 'all', 'institutions_open_to' => 'any',
            'other_conditions' => implode("\n", array_map(fn ($i) => "Condition $i", range(1, 11))),
        ])->assertSessionHasErrors('other_conditions');
    }

    public function test_the_form_asks_the_question(): void
    {
        $this->actingAs($this->provider)->get('/opportunities/create')->assertOk()
            ->assertSee('Who is it open to?')
            ->assertSee('All programmes at this level')
            ->assertSee('Any institution')
            ->assertSee('O-Level holders may apply')
            ->assertSee('Host universities (optional)')
            ->assertSee('Other conditions (optional)');
    }

    // ------------------------------------------------------------ old listings --

    public function test_answering_an_old_live_listing_with_all_programmes_does_not_send_it_back_for_review(): void
    {
        $listing = Opportunity::where('provider_user_id', $this->provider->user_id)
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->where('education_level', EducationLevel::UNDERGRADUATE)->firstOrFail();
        $listing->forceFill(['open_to_all_programmes' => null, 'any_institution' => null])->save();

        $this->actingAs($this->provider)->put('/opportunities/' . $listing->opportunity_id, [
            'title' => $listing->title, 'description' => $listing->description,
            'education_level' => $listing->education_level, 'country' => $listing->country,
            'funding_type' => $listing->funding_type, 'target_field' => $listing->target_field,
            'deadline' => $listing->deadline?->format('Y-m-d'),
            'minimum_education_level' => $listing->minimum_education_level,
            'open_to' => 'all', 'institutions_open_to' => 'any', 'reason' => 'Answering who it is open to',
        ])->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertTrue($listing->open_to_all_programmes);
        $this->assertTrue(OpportunityModerationStatus::isApproved($listing->moderation_status));
    }

    // ----------------------------------------------------- fields to study next --

    private function applicant(string $level): array
    {
        $user = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => $level])->save();

        return [$user, $profile];
    }

    public function test_a_graduate_can_name_up_to_three_fields_to_study_next(): void
    {
        [$user, $profile] = $this->applicant(EducationLevel::UNDERGRADUATE);

        $this->actingAs($user)->post(route('applicant.profile.programmes'), [
            'current_programme_id' => $this->programme('BCom Accounting'),
            'target_field_ids' => [$this->field('061'), $this->field('042')],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$this->field('061'), $this->field('042')],
            ApplicantTargetField::where('profile_id', $profile->profile_id)->pluck('field_id')->map(fn ($i) => (int) $i)->all()
        );
    }

    public function test_four_fields_to_study_next_are_refused(): void
    {
        [$user] = $this->applicant(EducationLevel::UNDERGRADUATE);

        $this->actingAs($user)->post(route('applicant.profile.programmes'), [
            'current_programme_id' => $this->programme('BCom Accounting'),
            'target_field_ids' => [$this->field('061'), $this->field('042'), $this->field('041'), $this->field('07')],
        ])->assertSessionHasErrors('target_field_ids');
    }

    public function test_a_school_leaver_chooses_programmes_not_fields_to_study_next(): void
    {
        [$user] = $this->applicant(EducationLevel::A_LEVEL);

        $this->actingAs($user)->post(route('applicant.profile.programmes'), [
            'target_field_ids' => [$this->field('061')],
        ])->assertSessionHasErrors('target_field_ids');
    }

    public function test_the_profile_offers_fields_to_study_next_to_a_graduate(): void
    {
        [$user] = $this->applicant(EducationLevel::UNDERGRADUATE);

        $this->actingAs($user)->get('/applicant/profile')->assertOk()->assertSee('Fields you want to study next');
    }
}
