<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Services\Catalogue\ListingScopes;
use App\Support\ApplicationStatus;
use App\Support\Deadlines;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * One scholarship, start to finish, through the real routes and the four kinds of visitor:
 * a provider posts it for specific programmes with an age limit, it is invisible until an administrator approves it, a
 * visitor can then find it, the student on that programme is told they qualify and applies, the student on another
 * programme in the same field and the student who is too old are not, and the provider decides
 * and the decision stays final. Each hand-over is checked from the side that receives it.
 */
class ScholarshipJourneyTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $provider;

    private User $admin;

    private User $match;

    private User $sibling;

    private User $tooOld;

    private Programme $theProgramme;

    private Programme $siblingProgramme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));
        Mail::fake();

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
        // A provider whose listings an administrator must approve, whatever their history.
        $this->provider->providerProfile->update(['trusted_override' => false]);
        \App\Support\ProviderTrust::class;

        $this->theProgramme = Programme::where('name', 'BSc Computer Science')->firstOrFail();
        $this->siblingProgramme = Programme::inCatalogue()->where('education_level', EducationLevel::UNDERGRADUATE)
            ->where('field_id', $this->theProgramme->field_id)->where('id', '!=', $this->theProgramme->id)->orderBy('id')->firstOrFail();

        $applicants = User::whereHas('role', fn ($q) => $q->where('role_name', 'ROLE_APPLICANT'))->orderBy('user_id')->limit(3)->get();
        [$this->match, $this->sibling, $this->tooOld] = [$applicants[0], $applicants[1], $applicants[2]];

        $this->settle($this->match, $this->theProgramme, 21);
        $this->settle($this->sibling, $this->siblingProgramme, 21);
        $this->settle($this->tooOld, $this->theProgramme, 40);
    }

    // ----------------------------------------------------------------- helpers --

    /** A complete profile for a school-leaver hoping to study this programme, at this age. */
    private function settle(User $user, Programme $programme, int $age): void
    {
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill([
            'education_level' => EducationLevel::A_LEVEL, 'institution_name' => 'Journey High School', 'field_of_study' => null,
            'province' => 'Harare', 'locality' => 'Harare', 'gender' => 'male', 'biography' => 'A student who wants to build useful software.',
            'date_of_birth' => Carbon::today()->subYears($age)->toDateString(), 'degree_classification' => null,
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ])->save();
        ApplicantProgramme::where('profile_id', $profile->profile_id)->delete();
        ApplicantProgramme::create(['profile_id' => $profile->profile_id, 'programme_id' => $programme->id, 'kind' => 'intended']);
        \App\Models\AcademicResult::where('profile_id', $profile->profile_id)->delete();
        $qualification = \App\Models\AcademicQualification::findByKey('zimsec_a_level') ?? \App\Models\AcademicQualification::firstOrFail();
        foreach ($qualification->activeSubjects()->orderBy('id')->limit(3)->get() as $subject) {
            \App\Models\AcademicResult::create(['profile_id' => $profile->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id, 'result' => 'B', 'derived_points' => $subject->pointsFor('B')]);
        }
        $this->giveRequiredDocuments($user);
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function listing(): Opportunity
    {
        return Opportunity::where('title', 'Journey Computing Bursary')->firstOrFail();
    }

    // ------------------------------------------------------------------ the journey --

    public function test_a_scholarship_goes_from_a_providers_form_to_a_decision(): void
    {
        // 1. The provider posts it: undergraduates on one programme, aged 30 or under, closing tomorrow week.
        $this->as($this->provider)->post('/opportunities/create', [
            'title' => 'Journey Computing Bursary', 'description' => 'Tuition for a computing undergraduate.',
            'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe', 'funding_type' => 'Full Scholarship',
            'open_to' => ListingScopes::OPEN_PROGRAMMES, 'scope_programmes' => [$this->theProgramme->id],
            'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY, 'max_age' => 30,
            'deadline' => Deadlines::today()->addDays(8)->toDateString(),
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();
        $this->assertSame(OpportunityModerationStatus::PENDING, $listing->moderation_status);
        $this->assertSame([$this->theProgramme->id], $listing->scopes->pluck('programme_id')->filter()->values()->all());
        $this->assertSame(30, $listing->max_age);

        // 2. Nobody can see it yet: not a visitor, not a student, not by its address.
        $this->as($this->match);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertStringNotContainsString('Journey Computing Bursary', $this->get('/scholarships?keyword=Journey')->assertOk()->getContent());
        $this->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();
        $this->as($this->match)->get('/scholarships/' . $listing->opportunity_id)->assertNotFound();
        $this->as($this->match)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count(), 'nobody can apply to an unapproved listing');

        // 3. The administrator approves it.
        $this->as($this->admin)->post('/admin/opportunities/' . $listing->opportunity_id . '/approve')->assertSessionHasNoErrors();
        $this->assertSame(OpportunityModerationStatus::APPROVED, $listing->fresh()->moderation_status);

        // 4. A visitor can now find it and read it.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertStringContainsString('Journey Computing Bursary', $this->get('/scholarships?keyword=Journey')->assertOk()->getContent());
        $detail = $this->get('/scholarships/' . $listing->opportunity_id)->assertOk()->getContent();
        $this->assertStringContainsString('Aged 30 or under', $detail);
        $this->assertStringContainsString('BSc Computer Science', $detail);

        // 5. The students are told the truth about themselves.
        $this->as($this->match)->get('/scholarships/' . $listing->opportunity_id)->assertOk();
        $evaluate = fn (User $student) => app(\App\Services\ScholarFit\EligibilityEvaluator::class)->evaluate(
            $student->applicantProfile()->first(), $listing->fresh(), \App\Services\ScholarFit\AcademicRecord::fromProfile($student->applicantProfile()->first())
        );
        $failed = fn (User $student) => collect($evaluate($student))->filter(fn ($o) => ! $o->passed && ! $o->advisory)->pluck('type')->all();

        $this->assertSame([], $failed($this->match), 'the student on the programme, within the age limit, qualifies');
        $this->assertContains('programme_scope', $failed($this->sibling), 'another programme in the same field does not');
        $this->assertContains('age', $failed($this->tooOld), 'the age limit is enforced');

        // 6. The student who qualifies applies; the others are stopped; nobody applies twice.
        $this->as($this->sibling)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->as($this->tooOld)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count(), 'neither was let through');

        $quick = $this->as($this->match)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->assertNull(session('errorMessage'), (string) session('errorMessage'));
        $quick->assertSessionHasNoErrors();
        $this->as($this->match)->post('/apply/' . $listing->opportunity_id . '/quick');
        $application = Application::where('opportunity_id', $listing->opportunity_id)->sole();
        $this->assertSame(ApplicationStatus::PENDING, $application->application_status);
        $this->assertSame($this->match->user_id, $application->user_id);

        // 7. The provider sees it, and only the provider who owns the listing can decide it.
        $this->as($this->provider)->get('/provider/applications/' . $application->application_id)->assertOk();
        $other = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->as($other)->post('/provider/applications/' . $application->application_id . '/review', ['status' => ApplicationStatus::ACCEPTED, 'reason' => 'Not mine.'])
            ->assertForbidden();
        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);

        // 8. Decide; the decision is final.
        $review = fn (string $status) => $this->as($this->provider)->post('/provider/applications/' . $application->application_id . '/review', ['status' => $status, 'reason' => 'After reading the application.']);
        $review(ApplicationStatus::REJECTED)->assertSessionHasNoErrors();
        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status);

        $review(ApplicationStatus::ACCEPTED);
        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status, 'a decision is not flipped in place');

        $this->as($this->provider)->post('/provider/applications/' . $application->application_id . '/reopen', ['reason' => 'I missed a document.'])->assertNotFound();
        $this->assertSame(ApplicationStatus::REJECTED, $application->fresh()->application_status, 'there is no way to withdraw it');

        // 9. The student sees the outcome; the record of it is kept.
        $this->as($this->match)->get('/my-applications')->assertOk()->assertSee('Journey Computing Bursary');
        $this->assertGreaterThanOrEqual(3, \App\Models\AuditLog::where('entity_id', $application->application_id)->count(), 'the decision is on the record');
    }

    public function test_an_unapproved_or_expired_scholarship_cannot_be_applied_to_even_with_the_address(): void
    {
        $field = Field::whereNotNull('parent_id')->orderBy('id')->firstOrFail();

        $this->as($this->provider)->post('/opportunities/create', [
            'title' => 'Journey Computing Bursary', 'description' => 'Tuition.', 'education_level' => EducationLevel::UNDERGRADUATE, 'country' => 'Zimbabwe',
            'open_to' => ListingScopes::OPEN_FIELDS, 'scope_fields' => [$field->id], 'institutions_open_to' => ListingScopes::INSTITUTIONS_ANY,
            'deadline' => Deadlines::earliest()->toDateString(),
        ])->assertSessionHasNoErrors();

        $listing = $this->listing();
        $this->as($this->admin)->post('/admin/opportunities/' . $listing->opportunity_id . '/approve');

        // The deadline passes while it is still marked published.
        $listing->forceFill(['deadline' => Carbon::today()->subDay()])->save();

        $this->as($this->match)->post('/apply/' . $listing->opportunity_id . '/quick');
        $this->assertSame(0, Application::where('opportunity_id', $listing->opportunity_id)->count());
        $this->assertStringNotContainsString('Journey Computing Bursary', $this->as($this->match)->get('/scholarships?keyword=Journey')->getContent());
    }
}
