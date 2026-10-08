<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Exceptions\GuardianRequiredException;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A minor must have a parent or guardian involved before they can SUBMIT an application - and not before
 * they can browse, read a listing, or build a profile. Minor means under 18 by date of birth; with no date
 * of birth, a pupil at school (Primary, O-Level, A-Level) counts as one.
 */
class GuardianGateTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // A complete profile, so that the guardian is the only thing standing between them and applying.
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->listing = Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Open Provider', 'title' => 'Open Award', 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function asMinor(array $guardian = []): void
    {
        $this->profile()->forceFill([
            'date_of_birth' => Carbon::today()->subYears(16),
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ] + [])->forceFill($guardian)->save();
    }

    private function fullGuardian(): array
    {
        return ['guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed_at' => now()];
    }

    private function apply(): Application
    {
        return app(ApplicationService::class)->submit($this->listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('A good reason. ', 10)]);
    }

    // --------------------------------------------------------- what it does not block --

    public function test_an_adult_is_never_asked_for_a_guardian(): void
    {
        $this->assertFalse($this->profile()->isMinor());

        $this->assertNotNull($this->apply());
    }

    public function test_a_minor_can_still_browse_and_read_a_listing(): void
    {
        $this->asMinor();

        $this->actingAs($this->student)->get('/scholarships')->assertOk();
        $this->actingAs($this->student)->get('/scholarships/' . $this->listing->opportunity_id)->assertOk();
        $this->actingAs($this->student)->get('/applicant/recommendations')->assertOk();
        $this->actingAs($this->student)->get('/applicant/dashboard')->assertOk();
    }

    public function test_a_minor_can_build_and_save_a_profile_without_a_guardian(): void
    {
        $this->asMinor();

        $this->actingAs($this->student)->post('/applicant/profile', [
            'full_name' => $this->student->full_name, 'gender' => 'male',
            'education_level' => EducationLevel::UNDERGRADUATE, 'date_of_birth' => Carbon::today()->subYears(16)->toDateString(),
            'province' => 'Harare', 'biography' => 'Hello',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Hello', $this->profile()->biography);
    }

    public function test_a_primary_pupil_can_save_a_profile_without_a_guardian_too(): void
    {
        $pupil = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        ApplicantProfile::where('user_id', $pupil->user_id)->update(['guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null]);

        $this->actingAs($pupil)->post('/applicant/profile', [
            'full_name' => $pupil->full_name, 'gender' => 'male',
            'education_level' => EducationLevel::PRIMARY, 'date_of_birth' => Carbon::today()->subYears(12)->toDateString(),
            'province' => 'Harare',
        ])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------- what it blocks --

    public function test_a_minor_without_a_guardian_cannot_submit_and_is_told_exactly_what_is_missing(): void
    {
        $this->asMinor();

        try {
            $this->apply();
            $this->fail('expected the guardian gate');
        } catch (GuardianRequiredException $e) {
            $this->assertCount(4, $e->missing);
            $this->assertStringContainsString('guardian\'s full name', $e->getMessage());
            $this->assertStringContainsString('guardian\'s phone number', $e->getMessage());
            $this->assertStringContainsString('relationship to your guardian', $e->getMessage());
            $this->assertStringContainsString('aware of and involved', $e->getMessage());
        }

        $this->assertSame(0, Application::where('opportunity_id', $this->listing->opportunity_id)->count());
    }

    public function test_each_missing_detail_blocks_on_its_own(): void
    {
        foreach (['guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed_at'] as $missing) {
            $this->asMinor($this->fullGuardian());
            $this->profile()->forceFill([$missing => null])->save();

            try {
                $this->apply();
                $this->fail("$missing should block");
            } catch (GuardianRequiredException $e) {
                $this->assertCount(1, $e->missing, $missing);
            }
        }
    }

    public function test_with_everything_given_a_minor_can_apply(): void
    {
        $this->asMinor($this->fullGuardian());

        $this->assertNotNull($this->apply());
        $this->assertSame(1, Application::where('opportunity_id', $this->listing->opportunity_id)->count());
    }

    public function test_it_is_the_date_of_birth_that_decides_not_the_level(): void
    {
        // An undergraduate who is 16 is a minor...
        $this->asMinor();
        $this->assertTrue($this->profile()->isMinor());
        $this->expectException(GuardianRequiredException::class);
        $this->apply();
    }

    public function test_no_birth_date_at_a_school_level_is_treated_as_a_minor(): void
    {
        $this->profile()->forceFill(['date_of_birth' => null, 'education_level' => EducationLevel::A_LEVEL, 'guardian_name' => null])->save();

        $this->expectException(GuardianRequiredException::class);
        $this->apply();
    }

    public function test_giving_a_birth_date_that_makes_them_an_adult_removes_the_requirement(): void
    {
        $this->profile()->forceFill(['date_of_birth' => null, 'education_level' => EducationLevel::A_LEVEL, 'guardian_name' => null])->save();
        $this->assertTrue($this->profile()->isMinor());

        $this->profile()->forceFill(['date_of_birth' => Carbon::today()->subYears(20)])->save();

        $this->assertFalse($this->profile()->isMinor());
        $this->assertSame([], $this->profile()->missingGuardianDetails());
    }

    public function test_the_gate_comes_before_everything_else_so_the_message_is_about_the_guardian(): void
    {
        $this->asMinor();
        $this->profile()->forceFill(['province' => null, 'education_level' => null])->save();   // also an incomplete profile

        $this->expectException(GuardianRequiredException::class);
        $this->apply();
    }

    // ------------------------------------------------------------------ the pages --

    public function test_the_wizard_says_what_is_missing_and_where_to_add_it(): void
    {
        $this->asMinor($this->fullGuardian());
        $this->profile()->forceFill(['guardian_phone' => null, 'guardian_confirmed_at' => null])->save();

        $html = html_entity_decode($this->actingAs($this->student)->get('/apply/' . $this->listing->opportunity_id)->assertOk()->getContent(), ENT_QUOTES);

        $this->assertStringContainsString('A PARENT OR GUARDIAN IS NEEDED', $html);
        $this->assertStringContainsString('under 18', $html);
        $this->assertStringContainsString('guardian\'s phone number', $html);
        $this->assertStringContainsString('aware of and involved', $html);
        $this->assertStringNotContainsString('guardian\'s full name', $html, 'only what is actually missing');
        $this->assertStringContainsString(route('applicant.profile') . '#guardian', $html);
        $this->assertStringNotContainsString('name="personal_statement"', $html, 'no form that cannot succeed');
    }

    public function test_the_wizard_opens_normally_once_it_is_all_there(): void
    {
        $this->asMinor($this->fullGuardian());

        $this->actingAs($this->student)->get('/apply/' . $this->listing->opportunity_id)->assertOk()
            ->assertDontSee('A PARENT OR GUARDIAN IS NEEDED')
            ->assertSee('name="personal_statement"', false);
    }

    public function test_one_click_apply_is_refused_with_the_same_message(): void
    {
        $this->asMinor();

        $this->actingAs($this->student)->post('/apply/' . $this->listing->opportunity_id . '/quick')
            ->assertSessionHas('errorMessage', fn (string $m) => str_contains($m, 'guardian\'s full name') && str_contains($m, 'under 18'));

        $this->assertSame(0, Application::where('opportunity_id', $this->listing->opportunity_id)->count());
    }

    public function test_posting_the_wizard_directly_is_refused_too(): void
    {
        $this->asMinor();

        $this->actingAs($this->student)->post('/apply/' . $this->listing->opportunity_id, [
            'personal_statement' => str_repeat('A good reason. ', 10), 'confirm' => '1',
        ])->assertSessionHas('errorMessage', fn (string $m) => str_contains($m, 'guardian'));

        $this->assertSame(0, Application::where('opportunity_id', $this->listing->opportunity_id)->count());
    }

    // ------------------------------------------------------------- the profile card --

    public function test_the_guardian_card_is_shown_to_a_minor_of_any_level_with_current_wording(): void
    {
        $this->asMinor();

        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="sz-guardian-card"(?![^>]*hidden)#', $html);
        $this->assertStringContainsString('under 18', $html);
        $this->assertStringContainsString('when you submit an application', $html);
        $this->assertStringContainsString('providers', strtolower($html));
        $this->assertStringNotContainsString('Form 1 awards', $html, 'the old, out-of-date wording is gone');
        $this->assertStringNotContainsString('only be able to apply to scholarships for Form 1', $html);
    }

    public function test_the_guardian_card_is_hidden_for_an_adult(): void
    {
        $html = $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#id="sz-guardian-card"[^>]*hidden#', $html);
    }

    public function test_the_card_is_shown_for_a_school_pupil_with_no_birth_date(): void
    {
        $this->profile()->forceFill(['date_of_birth' => null, 'education_level' => EducationLevel::O_LEVEL])->save();

        $this->assertDoesNotMatchRegularExpression('#id="sz-guardian-card"[^>]*hidden#',
            $this->actingAs($this->student)->get('/applicant/profile')->getContent());
    }

    // ------------------------------------------------------- saving the guardian --

    private function saveProfile(array $extra = [])
    {
        return $this->actingAs($this->student)->post('/applicant/profile', $extra + [
            'full_name' => $this->student->full_name, 'gender' => 'male',
            'education_level' => EducationLevel::UNDERGRADUATE, 'date_of_birth' => Carbon::today()->subYears(16)->toDateString(),
            'province' => 'Harare',
        ]);
    }

    public function test_a_minor_can_give_the_guardian_and_the_tick(): void
    {
        $this->asMinor();

        $this->saveProfile(['guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '1'])
            ->assertSessionHasNoErrors();

        $profile = $this->profile();
        $this->assertSame('Grace Moyo', $profile->guardian_name);
        $this->assertNotNull($profile->guardian_confirmed_at);
        $this->assertSame([], $profile->missingGuardianDetails());
    }

    public function test_unticking_the_confirmation_takes_it_back(): void
    {
        $this->asMinor($this->fullGuardian());

        $this->saveProfile(['guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->profile()->guardian_confirmed_at);
    }

    public function test_a_save_that_does_not_mention_the_tick_leaves_it_alone(): void
    {
        $this->asMinor($this->fullGuardian());

        app(ApplicantProfileService::class)->update($this->student, ['biography' => 'Only this changes']);

        $this->assertNotNull($this->profile()->guardian_confirmed_at);
    }

    public function test_the_guardian_details_are_validated_like_any_name_and_number(): void
    {
        $this->asMinor();

        $this->saveProfile(['guardian_name' => 'Grace123'])->assertSessionHasErrors('guardian_name');
        $this->saveProfile(['guardian_phone' => '12ab'])->assertSessionHasErrors('guardian_phone');
    }

    public function test_saving_as_an_adult_clears_the_guardian_details(): void
    {
        $this->asMinor($this->fullGuardian());

        $this->saveProfile(['date_of_birth' => Carbon::today()->subYears(25)->toDateString(), 'guardian_name' => 'Grace Moyo'])
            ->assertSessionHasNoErrors();

        $profile = $this->profile();
        $this->assertNull($profile->guardian_name);
        $this->assertNull($profile->guardian_confirmed_at);
    }
}
