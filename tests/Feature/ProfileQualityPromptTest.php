<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\User;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The yearly "confirm your current level" prompt and the consistency warnings, as the applicant sees them. */
class ProfileQualityPromptTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function age(int $days): void
    {
        $this->profile()->forceFill([
            'education_level_confirmed_at' => Carbon::now()->subDays($days),
            'created_at' => Carbon::now()->subDays($days),
        ])->save();
    }

    public function test_nobody_is_asked_to_confirm_a_level_they_have_just_stated(): void
    {
        $this->age(10);

        foreach (['/applicant/dashboard', '/applicant/profile'] as $page) {
            $this->actingAs($this->student)->get($page)->assertOk()->assertDontSee('confirm-level-prompt', false);
        }
    }

    public function test_after_a_year_the_dashboard_and_the_profile_ask(): void
    {
        $this->age(400);

        foreach (['/applicant/dashboard', '/applicant/profile'] as $page) {
            $this->actingAs($this->student)->get($page)->assertOk()
                ->assertSee('confirm-level-prompt', false)
                ->assertSee('still your current level');
        }
    }

    public function test_confirming_clears_the_prompt_and_changes_nothing_else(): void
    {
        $this->age(400);
        $before = $this->profile()->only(['education_level', 'field_of_study', 'province', 'date_of_birth']);

        $this->actingAs($this->student)->post('/applicant/profile/confirm-level')->assertRedirect();

        $profile = $this->profile();
        $this->assertTrue($profile->education_level_confirmed_at->isToday());
        $this->assertEquals($before, $profile->only(['education_level', 'field_of_study', 'province', 'date_of_birth']));

        $this->actingAs($this->student)->get('/applicant/dashboard')->assertDontSee('confirm-level-prompt', false);
    }

    public function test_saving_the_profile_form_also_counts_as_confirming(): void
    {
        $this->age(400);
        $profile = $this->profile();

        $this->actingAs($this->student)->post('/applicant/profile', [
            'full_name' => $this->student->full_name,
            'gender' => $profile->gender ?? 'MALE',
            'education_level' => $profile->education_level,
            'field_of_study' => $profile->field_of_study,
            'province' => $profile->province,
            'date_of_birth' => $profile->date_of_birth?->toDateString(),
        ]);

        $this->assertTrue($this->profile()->education_level_confirmed_at->isToday());
    }

    public function test_confirming_needs_a_level_and_only_works_for_your_own_profile(): void
    {
        $other = User::where('email', 'farai.sibanda@scholarzim.co.zw')->firstOrFail();
        $this->age(400);
        $otherBefore = ApplicantProfile::where('user_id', $other->user_id)->value('education_level_confirmed_at');

        $this->actingAs($this->student)->post('/applicant/profile/confirm-level');

        $this->assertEquals($otherBefore, ApplicantProfile::where('user_id', $other->user_id)->value('education_level_confirmed_at'));
    }

    public function test_a_provider_cannot_use_the_confirmation_route(): void
    {
        $provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($provider)->post('/applicant/profile/confirm-level')->assertForbidden();
    }

    public function test_an_inconsistent_profile_shows_the_warning_with_a_link_to_the_field(): void
    {
        $this->profile()->forceFill([
            'education_level' => EducationLevel::PRIMARY,
            'date_of_birth' => Carbon::today()->subYears(30),
        ])->save();

        $this->actingAs($this->student)->get('/applicant/profile')->assertOk()
            ->assertSee('profile-quality-warnings', false)
            ->assertSee('#date_of_birth', false);
    }

    public function test_a_consistent_profile_shows_no_warning(): void
    {
        $this->actingAs($this->student)->get('/applicant/profile')->assertOk()
            ->assertDontSee('profile-quality-warnings', false);
    }
}
