<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ScholarFit\EligibilityResult;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * A deadline is a calendar date in Harare, and everything that asks "is it still open?" must agree on it.
 *
 * The clock is pinned inside the two hours where UTC and Harare name different dates (22:00-24:00 UTC is 00:00-02:00 the
 * next day in Harare), because that is the only time a comparison made against the wrong date is visible. A listing whose
 * deadline is 22 October is open until the end of 22 October in Harare and closed from the first minute of the 23rd, even
 * though UTC still says the 22nd for another two hours.
 */
class DeadlineLifecycleTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $provider;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->student->applicantProfile->forceFill([
            'education_level' => EducationLevel::A_LEVEL, 'institution_name' => 'Journey High School', 'province' => 'Harare', 'locality' => 'Harare',
            'gender' => 'male', 'biography' => 'A school-leaver.', 'date_of_birth' => '2005-05-01',
        ])->save();
        $qualification = \App\Models\AcademicQualification::findByKey('zimsec_a_level') ?? \App\Models\AcademicQualification::firstOrFail();
        foreach ($qualification->activeSubjects()->orderBy('id')->limit(3)->get() as $subject) {
            \App\Models\AcademicResult::updateOrCreate(
                ['profile_id' => $this->student->applicantProfile->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id],
                ['result' => 'B', 'derived_points' => $subject->pointsFor('B')]
            );
        }
        $this->giveRequiredDocuments($this->student);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers --

    /** 23:30 on the 22nd in Harare (21:30 UTC) - or 00:30 on the 23rd (22:30 UTC). */
    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function listing(string $title, string $deadline, array $more = []): Opportunity
    {
        return Opportunity::create($more + [
            'provider_user_id' => $this->provider->user_id, 'provider_name' => $this->provider->full_name, 'title' => $title,
            'description' => 'A fixture.', 'education_level' => EducationLevel::UNDERGRADUATE, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => $deadline,
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => now()->subDays(9),
        ]);
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    // ----------------------------------------------- open until the end of the day --

    public function test_the_last_day_is_open_to_its_last_minute_in_harare(): void
    {
        $this->at('2026-10-22 21:30:00');   // 23:30 on the 22nd in Harare
        $last = $this->listing('Last Day Award', '2026-10-22');

        $this->assertTrue($last->acceptsApplications());
        $this->assertFalse($last->isExpired());
        $this->assertSame(0, $last->daysUntilDeadline());
        $this->assertTrue(Opportunity::publiclyVisible()->whereKey($last->opportunity_id)->exists());
        $this->assertStringContainsString('Last Day Award', $this->as($this->student)->get('/scholarships?keyword=Last+Day')->getContent());
    }

    public function test_it_is_closed_from_the_first_minute_of_the_next_day_in_harare_though_utc_still_says_the_same_day(): void
    {
        $this->at('2026-10-22 22:30:00');   // 00:30 on the 23rd in Harare; UTC's date is still the 22nd
        $this->assertSame('2026-10-22', now()->toDateString(), 'the precondition: UTC has not turned over');

        $expired = $this->listing('Expired Award', '2026-10-22');

        $this->assertFalse($expired->acceptsApplications());
        $this->assertTrue($expired->isExpired());
        $this->assertSame(-1, $expired->daysUntilDeadline());
        $this->assertFalse(Opportunity::publiclyVisible()->whereKey($expired->opportunity_id)->exists(), 'not offered in lists');
        $this->assertStringNotContainsString('Expired Award', $this->as($this->student)->get('/scholarships?keyword=Expired')->getContent(), 'not found by search');
        $this->assertStringNotContainsString('Expired Award', $this->as($this->student)->get('/applicant/recommendations')->getContent(), 'not recommended');

        $fit = new EligibilityResult($expired, [], true);
        $this->assertFalse($fit->listingIsOpen(), 'ScholarFit does not call it open either');
    }

    // ------------------------------------------------------------ no new applications --

    public function test_no_application_can_be_made_to_an_expired_listing_by_any_route(): void
    {
        $this->at('2026-10-22 22:30:00');
        $expired = $this->listing('Expired Award', '2026-10-22');

        $this->as($this->student);
        $this->assertNotSame(200, $this->get('/apply/' . $expired->opportunity_id)->getStatusCode(), 'the apply page is not offered');
        $this->post('/apply/' . $expired->opportunity_id . '/quick');
        $this->post('/apply/' . $expired->opportunity_id, ['statement' => 'Please consider me.']);
        $this->post('/apply/' . $expired->opportunity_id . '/details', ['biography' => 'x']);

        $this->assertSame(0, Application::where('opportunity_id', $expired->opportunity_id)->count());

        // The service is the last line: even called directly it refuses.
        $this->expectExceptionMessage('The deadline for this scholarship has passed.');
        app(\App\Services\ApplicationService::class)->submit($expired->opportunity_id, $this->student, []);
    }

    public function test_the_same_listing_took_applications_a_minute_earlier(): void
    {
        $this->at('2026-10-22 21:59:00');   // 23:59 on the 22nd in Harare
        $open = $this->listing('Still Open Award', '2026-10-22');

        $this->as($this->student)->post('/apply/' . $open->opportunity_id . '/quick');
        $this->assertNull(session('errorMessage'), (string) session('errorMessage'));

        $this->assertSame(1, Application::where('opportunity_id', $open->opportunity_id)->count(), 'applications are taken until the day ends in Harare');
    }

    // ------------------------------------------------------------- history is kept --

    public function test_the_sweep_closes_an_expired_listing_by_harare_date_and_keeps_its_applications(): void
    {
        $this->at('2026-10-22 21:59:00');
        $listing = $this->listing('Swept Award', '2026-10-22');
        $application = Application::forceCreate(['opportunity_id' => $listing->opportunity_id, 'user_id' => $this->student->user_id, 'application_status' => 'PENDING']);

        $this->artisan('scholarzim:archive-expired-opportunities')->assertExitCode(0);
        $this->assertSame(OpportunityStatus::ACTIVE, $listing->fresh()->status, 'still the last day in Harare: not closed yet');

        $this->at('2026-10-22 22:01:00');   // 00:01 on the 23rd in Harare
        $this->artisan('scholarzim:archive-expired-opportunities')->assertExitCode(0);

        $this->assertSame(OpportunityStatus::CLOSED, $listing->fresh()->status);
        $this->assertNotNull(Application::find($application->getKey()), 'the application is kept');
        $this->assertSame('PENDING', Application::find($application->getKey())->application_status, 'and not altered');
        $this->assertSame('2026-10-22', $listing->fresh()->deadline->toDateString(), 'the listing keeps its own deadline');
    }

    // ---------------------------------------- draft, edit, submit and extend agree --

    private function create(string $deadline, array $more = [])
    {
        return $this->as($this->provider)->post('/opportunities/create', $more + [
            'title' => 'Lifecycle Award', 'description' => 'A fixture.', 'education_level' => EducationLevel::FORM_1,
            'funding_type' => 'Full Scholarship', 'deadline' => $deadline,
        ]);
    }

    public function test_draft_submit_edit_and_extend_all_use_the_same_date(): void
    {
        $this->at('2026-10-22 22:30:00');   // the 23rd in Harare

        // Submit: the 23rd is today in Harare, so it is refused; the 24th is accepted.
        $this->create('2026-10-23')->assertSessionHasErrors('deadline');
        $this->create('2026-10-24')->assertSessionHasNoErrors();
        $live = Opportunity::where('title', 'Lifecycle Award')->firstOrFail();
        $this->assertSame('2026-10-24', $live->deadline->toDateString());

        // Draft: stored as given, but submitting it is judged the same way.
        $this->as($this->provider)->post('/opportunities/draft', ['title' => 'Lifecycle Draft', 'deadline' => '2026-10-23'])->assertSessionHasNoErrors();
        $draft = Opportunity::where('title', 'Lifecycle Draft')->firstOrFail();
        $this->create('2026-10-23', ['title' => 'Lifecycle Draft', 'draft_id' => $draft->opportunity_id])->assertSessionHasErrors('deadline');
        $this->assertTrue($draft->fresh()->isDraft());
        $this->create('2026-10-24', ['title' => 'Lifecycle Draft', 'draft_id' => $draft->opportunity_id])->assertSessionHasNoErrors();
        $this->assertFalse($draft->fresh()->isDraft());

        // Edit: moving it to "today" is refused, a later date is accepted.
        $edit = fn (string $date) => $this->as($this->provider)->put('/opportunities/' . $live->opportunity_id, [
            'title' => 'Lifecycle Award', 'description' => 'A fixture.', 'education_level' => EducationLevel::FORM_1, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'deadline' => $date, 'reason' => 'A change.',
        ]);
        $edit('2026-10-23')->assertSessionHasErrors('deadline');
        $edit('2026-10-25')->assertSessionHasNoErrors();

        // Extend: the same rule.
        $extend = fn (string $date) => $this->as($this->provider)->post('/opportunities/' . $live->opportunity_id . '/extend-deadline', ['deadline' => $date, 'reason' => 'More time.']);
        $extend('2026-10-23')->assertSessionHasErrors('deadline', null, 'extend-deadline-' . $live->opportunity_id);
        $extend('2026-10-26')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-26', $live->fresh()->deadline->toDateString());
    }

    public function test_extending_an_expired_listing_reopens_it_and_keeps_what_was_applied(): void
    {
        $this->at('2026-10-22 22:30:00');
        $listing = $this->listing('Reopened Award', '2026-10-20', ['status' => OpportunityStatus::CLOSED]);
        $application = Application::forceCreate(['opportunity_id' => $listing->opportunity_id, 'user_id' => $this->student->user_id, 'application_status' => 'PENDING']);

        $this->as($this->provider)->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', ['deadline' => '2026-10-23', 'reason' => 'x'])
            ->assertSessionHasErrors('deadline', null, 'extend-deadline-' . $listing->opportunity_id);
        $this->assertSame(OpportunityStatus::CLOSED, $listing->fresh()->status, 'today in Harare is not a future date');

        $this->as($this->provider)->post('/opportunities/' . $listing->opportunity_id . '/extend-deadline', ['deadline' => '2026-10-30', 'reason' => 'More time.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OpportunityStatus::ACTIVE, $listing->fresh()->status);
        $this->assertNotNull(Application::find($application->getKey()), 'history is kept');
    }
}
