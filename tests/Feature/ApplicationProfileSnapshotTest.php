<?php

namespace Tests\Feature;

use App\Models\AcademicResult;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\ApplicationStatus;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The provider reviews the application AS SUBMITTED: the recorded subject results and the profile fields they
 * are shown (and, for a minor, the guardian) are taken when the application is sent and kept with it. What the
 * applicant edits afterwards - a result, a province, a biography - does not change what the provider is looking at.
 */
class ApplicationProfileSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Snapshot Provider', 'title' => 'Open Award',
            'description' => 'No stated requirements.', 'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);

        $this->profile()->update([
            'province' => 'Harare', 'locality' => 'Harare', 'institution_name' => 'University of Zimbabwe (UZ)',
            'biography' => 'I study hard.', 'gender' => 'female', 'date_of_birth' => Carbon::today()->subYears(22),
        ]);

        // Recorded subject results to snapshot (the seeded student is a transcript applicant with none).
        $qualification = \App\Models\AcademicQualification::findByKey(\App\Support\Academic\AcademicCatalogue::ZIMSEC_A_LEVEL);
        foreach ($qualification->subjects()->orderBy('id')->limit(3)->get() as $subject) {
            AcademicResult::create([
                'profile_id' => $this->profile()->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id,
                'result' => 'B', 'derived_points' => $qualification->pointsFor('B'),
            ]);
        }
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function apply(): Application
    {
        return app(ApplicationService::class)->submit($this->listing->opportunity_id, $this->student->fresh(), ['personal_statement' => str_repeat('A good reason. ', 10)]);
    }

    private function providerSees(Application $application): string
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->provider)->get('/provider/applications/' . $application->application_id)->assertOk()->getContent();
    }

    private function firstResult(): AcademicResult
    {
        return $this->profile()->academicResults()->with('subject')->orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ results --

    public function test_the_provider_sees_the_results_as_they_were_when_the_application_was_sent(): void
    {
        $result = $this->firstResult();
        $subject = $result->subjectName();
        $sent = $result->result;
        $application = $this->apply();

        $result->update(['result' => $sent === 'U' ? 'E' : 'U']);   // the applicant edits it afterwards

        $row = collect($application->fresh()->submittedProfile()['results'])->firstWhere('subject', $subject);
        $this->assertSame($sent, $row['result'], 'what was sent is what is kept');

        $html = $this->providerSees($application);
        $this->assertStringContainsString($subject, $html);
        $this->assertSame($sent, $application->fresh()->submittedProfile()['results'][0]['result'] ?? $sent);
    }

    public function test_a_result_added_afterwards_does_not_appear(): void
    {
        $application = $this->apply();
        $count = $this->profile()->academicResults()->count();
        $this->assertGreaterThan(0, $count);
        $subject = $this->firstResult()->subject;

        AcademicResult::create([
            'profile_id' => $this->profile()->profile_id, 'qualification_id' => $this->firstResult()->qualification_id,
            'subject_id' => \App\Models\AcademicSubject::where('qualification_id', $this->firstResult()->qualification_id)->where('id', '!=', $subject->id)->whereNotIn('id', $this->profile()->academicResults()->pluck('subject_id'))->value('id'),
            'result' => 'A', 'derived_points' => 5,
        ]);

        $snapshot = $application->fresh()->submittedProfile();

        $this->assertCount($count, $snapshot['results']);
    }

    public function test_the_snapshot_holds_each_result_by_name_so_a_renamed_subject_cannot_change_it(): void
    {
        $application = $this->apply();
        $first = $application->fresh()->submittedProfile()['results'][0];

        $this->assertArrayHasKey('qualification', $first);
        $this->assertArrayHasKey('subject', $first);
        $this->assertArrayHasKey('result', $first);
        $this->assertArrayHasKey('points', $first);
    }

    // ----------------------------------------------------------- profile fields --

    public function test_profile_fields_edited_afterwards_do_not_change_what_the_provider_sees(): void
    {
        $application = $this->apply();

        $this->profile()->update([
            'province' => 'Bulawayo', 'locality' => 'Bulawayo', 'institution_name' => 'Somewhere Else',
            'biography' => 'A different story entirely.', 'gender' => 'male',
        ]);

        $html = $this->providerSees($application);

        $this->assertStringContainsString('University of Zimbabwe (UZ)', $html);
        $this->assertStringContainsString('I study hard.', $html);
        $this->assertStringContainsString('Harare', $html);
        $this->assertStringNotContainsString('Somewhere Else', $html);
        $this->assertStringNotContainsString('A different story entirely.', $html);
        $this->assertStringNotContainsString('Bulawayo', $html);
    }

    public function test_the_age_is_the_age_at_submission(): void
    {
        $application = $this->apply();

        $this->profile()->update(['date_of_birth' => Carbon::today()->subYears(40)]);

        $this->assertSame(22, $application->fresh()->submittedProfile()['profile']['age']);
    }

    public function test_the_level_the_provider_sees_is_the_level_it_was_sent_at(): void
    {
        $application = $this->apply();
        $level = $this->profile()->education_level;

        $this->profile()->update(['education_level' => \App\Support\EducationLevel::MASTERS]);

        $this->assertSame($level, $application->fresh()->submittedProfile()['profile']['education_level']);
    }

    // ----------------------------------------------------------- resubmission --

    public function test_resubmitting_after_a_withdrawal_takes_a_fresh_snapshot(): void
    {
        $application = $this->apply();
        $application->update(['application_status' => ApplicationStatus::WITHDRAWN, 'withdrawn_at' => now()]);
        $this->profile()->update(['biography' => 'My newer story.']);

        $this->apply();

        $this->assertSame('My newer story.', $application->fresh()->submittedProfile()['profile']['biography']);
    }

    // --------------------------------------------------- applications from before --

    public function test_an_application_with_no_snapshot_shows_the_live_profile_as_it_always_did(): void
    {
        $application = Application::create([
            'user_id' => $this->student->user_id, 'opportunity_id' => $this->listing->opportunity_id,
            'application_status' => ApplicationStatus::PENDING, 'personal_statement' => str_repeat('x', 120), 'submitted_at' => now(),
        ]);
        $this->assertNull($application->submitted_snapshot);

        $this->profile()->update(['biography' => 'Live story.']);

        $this->assertStringContainsString('Live story.', $this->providerSees($application));
    }

    // ------------------------------------------------------------ the eligibility panel --

    public function test_the_eligibility_check_says_it_is_worked_out_from_the_profile_today(): void
    {
        $html = $this->providerSees($this->apply());

        $this->assertStringContainsString('Worked out from the applicant\'s profile as it is today', $html);
    }

    // ------------------------------------------------------------------ a minor --

    private function makeMinor(): void
    {
        $this->profile()->forceFill([
            'date_of_birth' => Carbon::today()->subYears(16),
            'guardian_name' => 'Grace Moyo', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed_at' => now(),
        ])->save();
    }

    public function test_the_provider_sees_the_guardian_of_a_minor(): void
    {
        $this->makeMinor();

        $html = $this->providerSees($this->apply());

        $this->assertStringContainsString('Guardian', $html);
        $this->assertStringContainsString('Grace Moyo', $html);
        $this->assertStringContainsString('0771234567', $html);
        $this->assertStringContainsString('Mother', $html);
    }

    public function test_the_guardian_is_part_of_the_snapshot(): void
    {
        $this->makeMinor();
        $application = $this->apply();

        $this->profile()->update(['guardian_name' => 'Someone Else', 'guardian_phone' => '0779999999']);

        $guardian = $application->fresh()->submittedProfile()['guardian'];
        $this->assertSame('Grace Moyo', $guardian['name']);
        $this->assertSame('0771234567', $guardian['phone']);
        $this->assertSame('Mother', $guardian['relationship']);
        $this->assertStringContainsString('Grace Moyo', $this->providerSees($application));
        $this->assertStringNotContainsString('Someone Else', $this->providerSees($application));
    }

    public function test_an_adult_has_no_guardian_in_the_snapshot_or_on_the_page(): void
    {
        // Old guardian details left on an adult's profile are not shown to anyone.
        $this->profile()->forceFill(['guardian_name' => 'Old Guardian', 'guardian_phone' => '0771111111', 'guardian_relationship' => 'Aunt'])->save();
        $application = $this->apply();

        $this->assertNull($application->fresh()->submittedProfile()['guardian']);
        $this->assertStringNotContainsString('Old Guardian', $this->providerSees($application));
    }

    public function test_the_snapshot_says_whether_the_applicant_was_a_minor(): void
    {
        $this->makeMinor();

        $this->assertTrue($this->apply()->fresh()->submittedProfile()['minor']);
    }

    public function test_only_the_provider_of_that_award_sees_the_guardian(): void
    {
        $this->makeMinor();
        $application = $this->apply();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($other)->get('/provider/applications/' . $application->application_id)->assertForbidden();
    }
}
