<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicResult;
use App\Models\AcademicSubject;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentFile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Services\RecommendationService;
use App\Support\Academic\AcademicCatalogue;
use App\Support\ApplicationStatus;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GivesRequiredDocuments;
use Tests\TestCase;

/**
 * Enter it once, upload it once, and ScholarZim reuses it.
 *
 * The profile holds what is reusable; an application is the record of one submission; My Applications shows what
 * already exists. These tests follow an applicant through that: the apply page asks only for what is genuinely
 * missing, saves what it collects to the profile (never a second copy), keeps the statement that is particular to a
 * scholarship, freezes what was sent at submission, and leaves every eligibility, document, privacy and status rule
 * where it was.
 */
class ApplicationReuseTest extends TestCase
{
    use GivesRequiredDocuments;
    use RefreshDatabase;

    private User $student;

    private User $provider;

    /** The applications the seeded student already had before a test did anything. */
    private int $seededApplications = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();

        // A complete, settled profile: everything the apply page could ask for is already there.
        $this->profile()->forceFill([
            'education_level' => EducationLevel::UNDERGRADUATE, 'institution_name' => 'University of Zimbabwe',
            'field_of_study' => 'Computer Science', 'province' => 'Harare', 'locality' => 'Harare',
            'date_of_birth' => Carbon::today()->subYears(23)->toDateString(), 'gender' => 'male',
            'biography' => 'A student who wants to build useful software.', 'degree_classification' => 'Upper Second (2:1)',
            'guardian_name' => null, 'guardian_phone' => null, 'guardian_relationship' => null, 'guardian_confirmed_at' => null,
        ])->save();
        $this->giveRequiredDocuments($this->student);
        $this->seededApplications = Application::where('user_id', $this->student->user_id)->count();
    }

    // ------------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** One person at a time: the role-isolated session must not carry the last user over. */
    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // A fresh model, as every real request has: the one a test holds keeps the profile it loaded earlier.
        return $this->actingAs($user->fresh());
    }

    private function listing(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Reuse Provider',
            'title' => 'Reuse Award ' . uniqid(), 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ], $overrides));
    }

    private function wizard(Opportunity $listing, ?User $who = null): string
    {
        return $this->as($who ?? $this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
    }

    private function statement(): array
    {
        return ['personal_statement' => str_repeat('I want to study and give back. ', 5), 'confirm' => '1'];
    }

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% $name\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function details(Opportunity $listing, array $data, array $files = [])
    {
        return $this->as($this->student)->post(route('applications.details', $listing->opportunity_id), $data + $files);
    }

    /** A 17-year-old at A-Level with real results and the documents that level asks for - a minor with a settled profile. */
    private function asSchoolLeaver(): void
    {
        $this->profile()->forceFill([
            'date_of_birth' => Carbon::today()->subYears(17)->toDateString(), 'education_level' => EducationLevel::A_LEVEL,
            'degree_classification' => null, 'field_of_study' => null,
        ])->save();
        $this->giveResults();
        $this->giveRequiredDocuments($this->student);
    }

    private function giveResults(): void
    {
        $qualification = AcademicQualification::findByKey(AcademicCatalogue::ZIMSEC_A_LEVEL);
        $subject = AcademicSubject::where('qualification_id', $qualification->id)->where('name', 'Mathematics')->firstOrFail();

        AcademicResult::create([
            'profile_id' => $this->profile()->profile_id, 'qualification_id' => $qualification->id, 'subject_id' => $subject->id,
            'result' => 'A', 'year' => 2022, 'derived_points' => $subject->pointsFor('A'),
        ]);
    }

    // ============================================================ information reuse ==

    public function test_the_apply_page_reuses_the_profile_and_asks_for_nothing_it_already_has(): void
    {
        $html = $this->wizard($this->listing());

        $this->assertStringContainsString('What ScholarZim will send', $html);
        $this->assertStringContainsString('University of Zimbabwe', $html);
        $this->assertStringContainsString('Computer Science', $html);
        $this->assertStringNotContainsString('name="profile[', $html, 'no profile field is asked for again');
        $this->assertStringNotContainsString('name="documents[', $html, 'no document is asked for again');
        $this->assertStringContainsString('name="personal_statement"', $html);
        $this->assertStringContainsString('All your required documents are already on file', $html);
    }

    public function test_existing_valid_documents_are_reused_without_uploading_them_again(): void
    {
        $listing = $this->listing();
        $before = DocumentFile::count();
        $held = collect($this->profile()->documentChecklist()->required())->pluck('type')->all();

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())
            ->assertRedirect();

        $application = Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $this->assertSame($before, DocumentFile::count(), 'nothing was uploaded');
        $recorded = ApplicationDocument::where('application_id', $application->application_id)->get()->keyBy('type');
        foreach ($held as $type) {
            $this->assertSame($this->profile()->documentPath($type), $recorded[$type]->path, "$type is the file already on the profile");
        }
    }

    public function test_only_what_is_missing_is_asked_for_and_it_is_named(): void
    {
        $this->profile()->forceFill(['province' => null, 'date_of_birth' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);

        $html = $this->wizard($listing);

        $this->assertStringContainsString('A few details are still needed', $html);
        $this->assertStringContainsString('name="profile[province]"', $html);
        $this->assertStringContainsString('name="profile[date_of_birth]"', $html);
        foreach (['institution_name', 'field_of_study', 'biography', 'gender', 'locality'] as $field) {
            $this->assertStringNotContainsString('name="profile[' . $field . ']"', $html, "$field is already on file");
        }
        $this->assertStringNotContainsString('name="documents[', $html);
        $this->assertStringNotContainsString('name="personal_statement"', $html, 'the statement waits for the details');
        $this->assertStringContainsString('Province', $html);
        $this->assertStringContainsString('Date of birth', $html);
    }

    public function test_details_supplied_on_the_apply_page_are_saved_to_the_profile_and_nothing_else_changes(): void
    {
        $this->profile()->forceFill(['province' => null, 'date_of_birth' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);
        $before = $this->profile()->only(['institution_name', 'field_of_study', 'biography', 'gender', 'locality', 'education_level']);

        $this->details($listing, ['profile' => ['province' => 'Harare', 'date_of_birth' => '2000-05-17']])
            ->assertRedirect(route('applications.wizard', $listing->opportunity_id))
            ->assertSessionHas('successMessage', 'Saved to your profile.');

        $profile = $this->profile();
        $this->assertSame('Harare', $profile->province);
        $this->assertSame('2000-05-17', $profile->date_of_birth->toDateString());
        $this->assertSame($before, $profile->only(array_keys($before)), 'everything else is as it was');

        // And the apply page now opens the statement, because nothing is missing.
        $this->assertStringContainsString('name="personal_statement"', $this->wizard($listing));
        $this->assertStringNotContainsString('name="profile[province]"', $this->wizard($listing));
    }

    public function test_a_detail_the_profile_already_holds_is_never_overwritten(): void
    {
        $this->profile()->forceFill(['date_of_birth' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);

        $this->details($listing, ['profile' => [
            'date_of_birth' => '2000-05-17',
            'province' => 'Midlands', 'institution_name' => 'Somewhere Else', 'biography' => 'Rewritten',
        ]])->assertRedirect();

        $profile = $this->profile();
        $this->assertSame('2000-05-17', $profile->date_of_birth->toDateString(), 'the missing one was saved');
        $this->assertSame('Harare', $profile->province);
        $this->assertSame('University of Zimbabwe', $profile->institution_name);
        $this->assertSame('A student who wants to build useful software.', $profile->biography);
    }

    public function test_invalid_details_are_refused_and_nothing_is_saved(): void
    {
        $this->profile()->forceFill(['province' => null, 'date_of_birth' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);

        $this->details($listing, ['profile' => ['province' => 'Atlantis', 'date_of_birth' => Carbon::tomorrow()->toDateString()]])
            ->assertSessionHasErrors(['profile.province', 'profile.date_of_birth']);

        $this->details($listing, ['profile' => ['province' => 'Harare']])->assertSessionHasErrors('profile.date_of_birth');

        $profile = $this->profile();
        $this->assertNull($profile->province);
        $this->assertNull($profile->date_of_birth);
    }

    public function test_a_date_of_birth_that_does_not_fit_the_education_level_is_refused(): void
    {
        $this->profile()->forceFill(['date_of_birth' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);

        // An undergraduate who is eight: caught by the same consistency check the profile form applies.
        $this->details($listing, ['profile' => ['date_of_birth' => Carbon::today()->subYears(8)->toDateString()]])
            ->assertSessionHasErrors('profile.date_of_birth');

        $this->assertNull($this->profile()->date_of_birth);
    }

    public function test_a_province_that_contradicts_the_town_on_file_is_refused_beside_the_province(): void
    {
        $this->profile()->forceFill(['province' => null, 'locality' => 'Gwanda'])->save();
        $listing = $this->listing(['required_province' => 'Harare']);

        $this->details($listing, ['profile' => ['province' => 'Midlands']])->assertSessionHasErrors('profile.province');

        $this->assertNull($this->profile()->province);
    }

    public function test_a_town_that_is_not_in_the_province_is_refused(): void
    {
        $this->profile()->forceFill(['province' => null, 'locality' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare', 'target_locality' => 'Gwanda']);

        $this->details($listing, ['profile' => ['province' => 'Midlands', 'locality' => 'Gwanda']])
            ->assertSessionHasErrors('profile.locality');

        $this->assertNull($this->profile()->province);
    }

    public function test_a_minor_gives_a_guardian_in_place_and_it_is_saved_to_the_profile(): void
    {
        $this->asSchoolLeaver();
        $listing = $this->listing();

        $html = $this->wizard($listing);
        $this->assertStringContainsString('Parent or guardian', $html);
        $this->assertStringContainsString('under 18', $html);
        foreach (['guardian_name', 'guardian_phone', 'guardian_relationship', 'guardian_confirmed'] as $field) {
            $this->assertStringContainsString('name="profile[' . $field . ']"', $html);
        }

        $this->details($listing, ['profile' => [
            'guardian_name' => 'Mrs Chirwa', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed' => '1',
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $profile = $this->profile();
        $this->assertSame('Mrs Chirwa', $profile->guardian_name);
        $this->assertSame('Mother', $profile->guardian_relationship);
        $this->assertNotNull($profile->guardian_confirmed_at);
        $this->assertStringContainsString('name="personal_statement"', $this->wizard($listing), 'the application can go on');
    }

    public function test_a_guardian_is_not_asked_for_again_when_the_profile_has_one(): void
    {
        $this->asSchoolLeaver();
        $this->profile()->forceFill([
            'guardian_name' => 'Mrs Chirwa', 'guardian_phone' => '0771234567', 'guardian_relationship' => 'Mother', 'guardian_confirmed_at' => now(),
        ])->save();

        $html = $this->wizard($this->listing());

        $this->assertStringNotContainsString('Parent or guardian', $html);
        $this->assertStringNotContainsString('name="profile[guardian', $html);
    }

    public function test_what_only_the_profile_can_hold_is_a_link_with_a_way_back(): void
    {
        // An A-Level student states their results (from Certificate upward nothing academic is asked for).
        $this->profile()->forceFill(['education_level' => EducationLevel::A_LEVEL, 'degree_classification' => null, 'field_of_study' => null])->save();
        $this->giveRequiredDocuments($this->student);
        $this->profile()->academicResults()->delete();
        $listing = $this->listing();

        $html = $this->wizard($listing);

        $this->assertStringContainsString('Add these on your profile', $html);
        $this->assertStringContainsString('Academic results', $html);
        $this->assertStringContainsString(route('applicant.profile', ['apply' => $listing->opportunity_id]) . '#academic-results', $html);
        $this->assertStringNotContainsString('name="profile[academic', $html, 'a second copy of the results is never created here');

        $page = $this->as($this->student)->get(route('applicant.profile', ['apply' => $listing->opportunity_id]))->assertOk()->getContent();
        $this->assertStringContainsString('Back to my application', $page);
        $this->assertStringContainsString(route('applications.wizard', $listing->opportunity_id), $page);

        $plain = $this->as($this->student)->get(route('applicant.profile'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Back to my application', $plain);
    }

    public function test_a_scholarship_specific_statement_is_still_collected(): void
    {
        $listing = $this->listing();
        $post = fn (array $data) => $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $data);

        $post(['confirm' => '1'])->assertSessionHasErrors('personal_statement');
        $post(['personal_statement' => str_repeat('x', 99), 'confirm' => '1'])->assertSessionHasErrors('personal_statement');
        $post(['personal_statement' => str_repeat('x', 120)])->assertSessionHasErrors('confirm');

        $this->assertDatabaseMissing('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);

        $post($this->statement())->assertRedirect();
        $this->assertDatabaseHas('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    // ----------------------------------------------------------- documents, in place --

    public function test_a_missing_document_is_uploaded_on_the_apply_page_and_saved_to_the_profile(): void
    {
        $this->profile()->forceFill(['passport_path' => null, 'passport_filename' => null])->save();
        $listing = $this->listing();

        $html = $this->wizard($listing);
        $this->assertStringContainsString('name="documents[passport]"', $html);
        $this->assertStringNotContainsString('name="documents[transcript]"', $html, 'the ones on file are not asked for');
        $this->assertStringContainsString('1 document is still needed.', $html);

        $this->details($listing, ['documents' => ['passport' => $this->pdf('id.pdf')]])->assertRedirect()->assertSessionHasNoErrors();

        $profile = $this->profile();
        $this->assertTrue($profile->documentChecklist($listing)->isComplete());
        $this->assertNotNull($profile->documentPath('passport'));
        Storage::disk((string) config('filesystems.default', 'local'))->assertExists($profile->documentPath('passport'));
        $this->assertStringContainsString('name="personal_statement"', $this->wizard($listing));
    }

    public function test_a_file_that_is_not_a_document_is_refused_and_nothing_is_stored(): void
    {
        $this->profile()->forceFill(['passport_path' => null, 'passport_filename' => null])->save();
        $listing = $this->listing();

        $this->details($listing, ['documents' => ['passport' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]])
            ->assertSessionHasErrors('documents.passport');

        $this->assertNull($this->profile()->documentPath('passport'));
    }

    public function test_the_apply_page_never_asks_for_a_document_the_profile_already_has_even_if_posted(): void
    {
        $listing = $this->listing();
        $before = $this->profile()->documentPath('transcript');

        $this->details($listing, ['documents' => ['transcript' => $this->pdf('new.pdf')]])->assertRedirect(route('applications.wizard', $listing->opportunity_id));

        $this->assertSame($before, $this->profile()->documentPath('transcript'), 'only what is missing can be supplied here');
    }

    public function test_documents_are_not_collected_for_a_scholarship_the_applicant_cannot_be_considered_for(): void
    {
        $this->profile()->forceFill(['passport_path' => null, 'passport_filename' => null])->save();
        $listing = $this->listing(['required_province' => 'Manicaland']);

        $html = $this->wizard($listing);

        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringNotContainsString('name="documents[', $html);

        $this->details($listing, ['documents' => ['passport' => $this->pdf('id.pdf')]])->assertRedirect(route('applications.wizard', $listing->opportunity_id));
        $this->assertNull($this->profile()->documentPath('passport'), 'nothing was stored for an award they cannot be considered for');
    }

    // ============================================ institutions: asked only when a scholarship needs them ==

    private function institutionId(string $code): int
    {
        return \App\Models\Institution::where('code', $code)->value('id');
    }

    /** A scholarship limited to named institutions, open to every programme. */
    private function institutionListing(string ...$codes): Opportunity
    {
        $listing = $this->listing(['open_to_all_programmes' => true, 'any_institution' => false]);

        foreach ($codes as $code) {
            \App\Models\OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'institution_id' => $this->institutionId($code)]);
        }

        return $listing;
    }

    private function asAdultSchoolLeaver(): void
    {
        $this->asSchoolLeaver();
        $this->profile()->forceFill(['date_of_birth' => Carbon::today()->subYears(19)->toDateString()])->save();
        \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->delete();
    }

    public function test_a_school_leaver_is_asked_where_they_applied_only_for_a_scholarship_limited_to_institutions(): void
    {
        $this->asAdultSchoolLeaver();

        $plain = $this->wizard($this->listing(['open_to_all_programmes' => true, 'any_institution' => true]));
        $this->assertStringNotContainsString('applied_institution_ids', $plain, 'a scholarship open to any institution never asks');
        $this->assertStringContainsString('name="personal_statement"', $plain);

        $restricted = $this->institutionListing('MSU');
        $html = $this->wizard($restricted);

        $this->assertStringContainsString('A few details are still needed', $html);
        $this->assertStringContainsString('name="profile[applied_institution_ids][]"', $html);
        $this->assertStringContainsString('Institutions you have applied to or hold an offer from', $html);
        $this->assertStringContainsString('This scholarship is for students at certain institutions.', $html);
        $this->assertStringNotContainsString('name="profile[institution_name]"', $html, 'nothing else is asked for');
        $this->assertStringNotContainsString('name="personal_statement"', $html);
    }

    public function test_the_institutions_given_on_the_apply_page_are_saved_and_the_application_goes_on(): void
    {
        $this->asAdultSchoolLeaver();
        $listing = $this->institutionListing('MSU');

        $this->details($listing, ['profile' => ['applied_institution_ids' => [$this->institutionId('MSU'), $this->institutionId('UZ')]]])
            ->assertRedirect(route('applications.wizard', $listing->opportunity_id))
            ->assertSessionHasNoErrors();

        $saved = \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->pluck('institution_id')->all();
        $this->assertEqualsCanonicalizing([$this->institutionId('MSU'), $this->institutionId('UZ')], $saved);

        $html = $this->wizard($listing);
        $this->assertStringContainsString('name="personal_statement"', $html, 'applied to MSU, which is on the list: nothing more is asked');
        $this->assertStringNotContainsString('applied_institution_ids', $html);
    }

    public function test_the_institutions_answer_is_validated(): void
    {
        $this->asAdultSchoolLeaver();
        $listing = $this->institutionListing('MSU');
        $ids = fn (string ...$codes) => array_map(fn ($code) => $this->institutionId($code), $codes);

        $this->details($listing, ['profile' => ['applied_institution_ids' => $ids('UZ', 'MSU', 'NUST', 'CUT')]])->assertSessionHasErrors('profile.applied_institution_ids');
        $this->details($listing, ['profile' => ['applied_institution_ids' => [999999]]])->assertSessionHasErrors('profile.applied_institution_ids');
        $this->details($listing, ['profile' => ['applied_institution_ids' => []]])->assertSessionHasErrors('profile.applied_institution_ids');

        $this->assertSame(0, \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->count());
    }

    public function test_institutions_already_saved_are_not_asked_for_again(): void
    {
        $this->asAdultSchoolLeaver();
        \App\Models\ApplicantInstitution::create(['profile_id' => $this->profile()->profile_id, 'institution_id' => $this->institutionId('MSU')]);

        $html = $this->wizard($this->institutionListing('MSU', 'UZ'));

        $this->assertStringNotContainsString('applied_institution_ids', $html);
        $this->assertStringContainsString('name="personal_statement"', $html);
    }

    public function test_the_question_is_not_asked_of_someone_who_is_already_studying(): void
    {
        // The seeded undergraduate: where they study comes from their programme, which only the profile holds.
        $listing = $this->institutionListing('MSU');

        $html = $this->wizard($listing);

        $this->assertStringNotContainsString('applied_institution_ids', $html);
        $this->assertStringNotContainsString('Institutions you have applied to', $html);
    }

    public function test_the_matches_page_sends_a_school_leaver_to_the_application_not_the_profile_for_it(): void
    {
        $this->asAdultSchoolLeaver();
        Opportunity::query()->update(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $listing = $this->institutionListing('MSU');
        $listing->forceFill(['title' => 'Institution Limited Award'])->save();

        $html = $this->as($this->student)->get(route('applicant.recommendations'))->assertOk()->getContent();

        $this->assertStringContainsString('Institution Limited Award', $html);
        $this->assertStringContainsString('Say where you applied', $html);
        $this->assertStringContainsString(route('applications.wizard', $listing->opportunity_id), $html);
        $this->assertStringNotContainsString('Add your institution', $html, 'there is no profile field to send them to');
        $this->assertStringNotContainsString('to your profile so we can check it', str_replace('add where you study to your profile so we can check it', '', $html));
    }

    public function test_what_was_saved_on_an_application_is_reused_by_the_next_one_that_needs_it(): void
    {
        $this->asAdultSchoolLeaver();
        $first = $this->institutionListing('MSU');
        $second = $this->institutionListing('MSU', 'NUST');

        $this->details($first, ['profile' => ['applied_institution_ids' => [$this->institutionId('MSU')]]]);

        $this->assertStringContainsString('name="personal_statement"', $this->wizard($second), 'asked once, used for the next');
    }

    /** Applications this test has caused to exist. */
    private function applicationsMade(): int
    {
        return Application::where('user_id', $this->student->user_id)->count() - $this->seededApplications;
    }

    /** The institution ids ticked in the control on a page. @return array<int, int> */
    private function tickedInstitutions(string $html): array
    {
        preg_match_all('/value="(\d+)"\s+id="applied_institution_ids-\d+"\s+checked/', $html, $m);

        return collect($m[1])->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return array<int, int> what the profile holds, sorted */
    private function savedInstitutions(): array
    {
        return \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)
            ->pluck('institution_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function saveInstitutions(string ...$codes): void
    {
        foreach ($codes as $code) {
            \App\Models\ApplicantInstitution::create(['profile_id' => $this->profile()->profile_id, 'institution_id' => $this->institutionId($code)]);
        }
    }

    private function ids(string ...$codes): array
    {
        return collect($codes)->map(fn ($code) => $this->institutionId($code))->sort()->values()->all();
    }

    private function institutionsPost(Opportunity $listing, array $ids, array $more = [])
    {
        return $this->details($listing, array_replace_recursive(['profile' => ['applied_institution_ids' => $ids]], $more));
    }

    private function submitFor(Opportunity $listing, array $extra = [])
    {
        return $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement() + $extra);
    }

    public function test_the_apply_page_asks_for_a_missing_gender_in_plain_words(): void
    {
        $this->profile()->forceFill(['gender' => null])->save();
        $listing = $this->listing();

        $this->details($listing, ['profile' => ['gender' => '']])->assertSessionHasErrors(['profile.gender' => 'Please select your gender.']);

        $page = $this->wizard($listing);
        $this->assertStringNotContainsString('profile.gender', strip_tags($page));
        $this->assertSame([], \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->pluck('id')->all());
        $this->assertNull($this->profile()->gender, 'nothing was saved');
    }

    public function test_a_missing_document_on_the_apply_page_names_the_document_in_plain_words(): void
    {
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();
        $listing = $this->listing();

        $this->details($listing, [])->assertSessionHasErrors(['documents.recommendation' => 'Please choose a file for the recommendation letter.']);
    }

    public function test_with_no_saved_institutions_the_question_is_shown_with_nothing_ticked(): void
    {
        $this->asAdultSchoolLeaver();

        $html = $this->wizard($this->institutionListing('MSU'));

        $this->assertStringContainsString('name="profile[applied_institution_ids][]"', $html);
        $this->assertSame([], $this->tickedInstitutions($html));
        $this->assertStringNotContainsString('id="correct-institutions"', $html, 'nothing is on file to correct');
        $this->assertStringContainsString('A few details are still needed', $html);
    }

    public function test_saved_institutions_that_satisfy_the_scholarship_are_reused_and_left_exactly_as_they_were(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('MSU', 'UZ', 'NUST');
        $listing = $this->institutionListing('MSU');

        $html = $this->wizard($listing);

        $this->assertStringNotContainsString('applied_institution_ids', $html, 'not asked again');
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('name="personal_statement"', $html);

        $this->submitFor($listing)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->applicationsMade());
        $this->assertSame($this->ids('MSU', 'UZ', 'NUST'), $this->savedInstitutions(), 'all three still there');
    }

    public function test_saved_institutions_that_do_not_satisfy_the_scholarship_are_shown_for_correction_with_the_saved_list_ticked(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ', 'NUST');
        $listing = $this->institutionListing('MSU');

        $html = $this->wizard($listing);

        $this->assertStringContainsString('NOT ELIGIBLE', $html, 'the reason is still given');
        $this->assertStringContainsString('id="correct-institutions"', $html);
        $this->assertStringContainsString('Save and check again', $html);
        $this->assertStringContainsString('none of the ones you listed is one of them', $html);
        $this->assertSame($this->ids('UZ', 'NUST'), $this->tickedInstitutions($html), 'the saved selection is the starting point');
        $this->assertStringNotContainsString('name="personal_statement"', $html, 'no submission while ineligible');
        $this->assertSame($this->ids('UZ', 'NUST'), $this->savedInstitutions(), 'looking at the page changes nothing');
    }

    public function test_an_applicant_who_adds_the_required_institution_is_reassessed_and_can_apply(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ');
        $listing = $this->institutionListing('MSU');

        $this->institutionsPost($listing, $this->ids('UZ', 'MSU'))
            ->assertRedirect(route('applications.wizard', $listing->opportunity_id))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->ids('UZ', 'MSU'), $this->savedInstitutions(), 'added, not replaced');

        $html = $this->wizard($listing);
        $this->assertStringNotContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('name="personal_statement"', $html);

        $this->submitFor($listing)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->applicationsMade());
    }

    public function test_an_applicant_can_edit_or_remove_institutions_and_the_list_is_what_they_chose(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ', 'NUST', 'CUT');
        $listing = $this->institutionListing('MSU');

        // Removes two, keeps one, adds the one the scholarship needs.
        $this->institutionsPost($listing, $this->ids('NUST', 'MSU'))->assertSessionHasNoErrors();
        $this->assertSame($this->ids('NUST', 'MSU'), $this->savedInstitutions());

        // Once the list satisfies the scholarship it is reused, not offered: a further post changes nothing.
        $this->institutionsPost($listing, $this->ids('UZ'))->assertSessionHasNoErrors();
        $this->assertSame($this->ids('NUST', 'MSU'), $this->savedInstitutions());

        // Narrowing a list that does not fit to the one institution that does is a deliberate choice, and is kept as made.
        \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->delete();
        $this->saveInstitutions('UZ', 'NUST');
        $this->institutionsPost($listing, $this->ids('MSU'))->assertSessionHasNoErrors();
        $this->assertSame($this->ids('MSU'), $this->savedInstitutions());

        // Nothing at all is not an answer: the list is kept rather than cleared.
        \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->delete();
        $this->saveInstitutions('UZ');
        $this->institutionsPost($listing, [])->assertSessionHasErrors('profile.applied_institution_ids');
        $this->assertSame($this->ids('UZ'), $this->savedInstitutions());
    }

    public function test_an_applicant_who_is_still_not_on_the_list_after_correcting_it_remains_ineligible(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ');
        $listing = $this->institutionListing('MSU');

        $this->institutionsPost($listing, $this->ids('NUST'))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->ids('NUST'), $this->savedInstitutions(), 'what they said is kept');

        $html = $this->wizard($listing);
        $this->assertStringContainsString('NOT ELIGIBLE', $html);
        $this->assertStringContainsString('id="correct-institutions"', $html, 'and they can try again');
        $this->assertSame($this->ids('NUST'), $this->tickedInstitutions($html));
        $this->assertStringNotContainsString('name="personal_statement"', $html);

        // The page is not the control: a direct submission is refused with the reason, and no application exists.
        $this->submitFor($listing)->assertSessionHas('errorMessage');
        $this->assertStringContainsString('do not meet', (string) session('errorMessage'));
        $this->assertSame(0, $this->applicationsMade());
    }

    public function test_invalid_institution_identifiers_are_refused_and_save_nothing(): void
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ', 'NUST');
        $listing = $this->institutionListing('MSU');
        \App\Models\Institution::where('code', 'CUT')->update(['is_active' => false]);

        foreach ([
            'unknown id' => [[999999], 'profile.applied_institution_ids'],
            'switched off' => [$this->ids('CUT'), 'profile.applied_institution_ids'],
            'four' => [$this->ids('UZ', 'MSU', 'NUST', 'CUT'), 'profile.applied_institution_ids'],
            'not a number' => [['abc'], 'profile.applied_institution_ids.0'],
            'nested' => [[[1]], 'profile.applied_institution_ids.0'],
        ] as $case => [$ids, $key]) {
            $this->institutionsPost($listing, $ids)->assertSessionHasErrors($key);
            $this->assertSame($this->ids('UZ', 'NUST'), $this->savedInstitutions(), $case . ': the saved list is untouched');
        }

        $this->details($listing, ['profile' => ['applied_institution_ids' => 'not-an-array']])->assertSessionHasErrors('profile.applied_institution_ids');
        $this->assertSame($this->ids('UZ', 'NUST'), $this->savedInstitutions());
    }

    public function test_a_failed_institution_update_saves_no_other_detail_or_document(): void
    {
        $this->asAdultSchoolLeaver();
        $this->profile()->forceFill(['gender' => null, 'recommendation_letter_path' => null, 'recommendation_letter_filename' => null])->save();
        $this->saveInstitutions('UZ');
        $listing = $this->institutionListing('MSU');
        // Nothing on file for this one: the question, a missing detail and a missing document are all on the page.
        \App\Models\ApplicantInstitution::where('profile_id', $this->profile()->profile_id)->delete();

        $html = $this->wizard($listing);
        $this->assertStringContainsString('name="profile[gender]"', $html);
        $this->assertStringContainsString('name="documents[recommendation]"', $html);
        $before = $this->profile()->only(['gender', 'recommendation_letter_path']);
        $filesBefore = Storage::disk((string) config('filesystems.default', 'local'))->allFiles();

        $this->details($listing, ['profile' => ['gender' => 'female', 'applied_institution_ids' => [999999]]], ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])
            ->assertSessionHasErrors('profile.applied_institution_ids');

        $this->assertSame($before, $this->profile()->only(['gender', 'recommendation_letter_path']), 'nothing beside it was saved');
        $this->assertSame($filesBefore, Storage::disk((string) config('filesystems.default', 'local'))->allFiles(), 'no file was written');
        $this->assertSame([], $this->savedInstitutions());

        // The same request with a good answer saves all of it.
        $this->details($listing, ['profile' => ['gender' => 'female', 'applied_institution_ids' => $this->ids('MSU')]], ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])
            ->assertSessionHasNoErrors();
        $this->assertSame('female', $this->profile()->gender);
        $this->assertNotNull($this->profile()->recommendation_letter_path);
        $this->assertSame($this->ids('MSU'), $this->savedInstitutions());
    }

    // ===================== a refused submission leaves nothing behind =====================

    /** An adult school-leaver who is certainly not eligible for a scholarship limited to MSU, and is missing the letter. */
    private function ineligibleWithNoLetter(): Opportunity
    {
        $this->asAdultSchoolLeaver();
        $this->saveInstitutions('UZ');
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();

        return $this->institutionListing('MSU');
    }

    private function assertNothingWasKept(): void
    {
        $this->assertSame(0, $this->applicationsMade(), 'no application');
        $this->assertNull($this->profile()->recommendation_letter_path, 'no document on the profile');
        $this->assertSame([], Storage::disk((string) config('filesystems.default', 'local'))->allFiles(), 'no file in storage');
    }

    public function test_a_crafted_submission_from_an_ineligible_applicant_stores_no_document(): void
    {
        $listing = $this->ineligibleWithNoLetter();

        $this->submitFor($listing, ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');

        $this->assertStringContainsString('do not meet', (string) session('errorMessage'));
        $this->assertNothingWasKept();
    }

    public function test_a_crafted_submission_for_a_listing_that_cannot_take_it_stores_no_document(): void
    {
        $this->asAdultSchoolLeaver();
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();

        $closed = $this->listing(['status' => OpportunityStatus::CLOSED]);
        $this->submitFor($closed, ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');
        $this->assertNothingWasKept();

        $expired = $this->listing(['deadline' => Carbon::today()->subDay()]);
        $this->submitFor($expired, ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');
        $this->assertNothingWasKept();
    }

    public function test_a_crafted_submission_from_an_incomplete_profile_stores_no_document(): void
    {
        $this->asAdultSchoolLeaver();
        $this->profile()->forceFill(['biography' => null, 'recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();

        $this->submitFor($this->listing(), ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');

        $this->assertNothingWasKept();
    }

    public function test_a_crafted_submission_from_a_minor_without_a_guardian_stores_no_document(): void
    {
        $this->asSchoolLeaver();
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();

        $this->submitFor($this->listing(), ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');

        $this->assertNothingWasKept();
    }

    public function test_a_crafted_second_submission_for_the_same_scholarship_stores_no_document(): void
    {
        $this->asAdultSchoolLeaver();
        $listing = $this->listing();
        $this->submitFor($listing)->assertRedirect();

        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();
        $this->submitFor($listing, ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertSessionHas('errorMessage');

        $this->assertNull($this->profile()->recommendation_letter_path);
        $this->assertSame([], Storage::disk((string) config('filesystems.default', 'local'))->allFiles());
        $this->assertSame(1, $this->applicationsMade());
    }

    public function test_a_file_that_is_not_an_acceptable_document_is_refused_before_anything_else(): void
    {
        $this->asAdultSchoolLeaver();
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();

        $this->submitFor($this->listing(), ['documents' => ['recommendation' => UploadedFile::fake()->create('letter.exe', 10, 'application/x-msdownload')]])
            ->assertSessionHasErrors('documents.recommendation');

        $this->assertNothingWasKept();
    }

    public function test_an_eligible_applicant_still_has_the_document_they_send_stored_and_the_application_made(): void
    {
        $this->asAdultSchoolLeaver();
        $this->profile()->forceFill(['recommendation_letter_path' => null, 'recommendation_letter_filename' => null, 'recommendation_letter_uploaded_at' => null])->save();
        $listing = $this->listing();

        $this->submitFor($listing, ['documents' => ['recommendation' => $this->pdf('letter.pdf')]])->assertRedirect()->assertSessionHasNoErrors();

        $application = Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $this->assertNotNull($this->profile()->recommendation_letter_path);
        $this->assertSame($this->profile()->recommendation_letter_path, $application->documentFor('recommendation')['path'] ?? null, 'it is the one the application went in with');
        $this->assertTrue(Storage::disk((string) config('filesystems.default', 'local'))->exists($this->profile()->recommendation_letter_path));
    }

    public function test_quick_apply_is_held_to_the_same_rules(): void
    {
        $listing = $this->ineligibleWithNoLetter();

        $this->as($this->student)->post(route('applications.quick', $listing->opportunity_id))->assertSessionHas('errorMessage');

        $this->assertNothingWasKept();
    }

    // ===================================================== eligibility is not bypassed ==

    public function test_a_complete_profile_does_not_make_an_applicant_eligible_everywhere(): void
    {
        $listing = $this->listing(['required_province' => 'Manicaland']);

        $this->assertStringContainsString('NOT ELIGIBLE', $this->wizard($listing));

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertSessionHas('errorMessage');
        $this->as($this->student)->post('/apply/' . $listing->opportunity_id . '/quick')->assertSessionHas('errorMessage');

        $this->assertDatabaseMissing('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    public function test_scholarfit_reads_the_detail_saved_on_the_apply_page(): void
    {
        $this->profile()->forceFill(['province' => null, 'locality' => null])->save();
        $listing = $this->listing(['required_province' => 'Harare']);
        $service = app(RecommendationService::class);

        $this->assertTrue($service->evaluateOne($this->student->fresh(), $listing)->needsInformation(), 'cannot be checked yet');

        $this->details($listing, ['profile' => ['province' => 'Bulawayo']])->assertRedirect();

        $fit = $service->evaluateOne($this->student->fresh(), $listing);
        $this->assertTrue($fit->isIneligible(), 'the saved province is what ScholarFit now judges');
        $this->assertStringContainsString('NOT ELIGIBLE', $this->wizard($listing));
    }

    public function test_the_required_academic_document_cannot_be_skipped(): void
    {
        $this->profile()->forceFill(['transcript_path' => null, 'transcript_filename' => null])->save();
        $listing = $this->listing();

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())
            ->assertSessionHasErrors('documents.transcript');
        $this->as($this->student)->post('/apply/' . $listing->opportunity_id . '/quick')->assertSessionHas('errorMessage');

        $this->assertDatabaseMissing('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    public function test_academic_results_are_kept_when_the_profile_is_saved_without_that_section(): void
    {
        $this->giveResults();
        $count = $this->profile()->academicResults()->count();

        $this->as($this->student)->post('/applicant/profile', [
            'full_name' => $this->student->full_name, 'gender' => 'male', 'education_level' => EducationLevel::UNDERGRADUATE,
        ])->assertSessionHasNoErrors();

        $this->assertSame($count, $this->profile()->academicResults()->count(), 'results are touched only when their section is submitted');
    }

    public function test_moving_to_a_level_that_hides_the_results_editor_does_not_delete_the_results(): void
    {
        $this->giveResults();
        $count = $this->profile()->academicResults()->count();

        app(ApplicantProfileService::class)->update($this->student, ['education_level' => EducationLevel::MASTERS]);

        $this->assertSame($count, $this->profile()->academicResults()->count());
    }

    // ===================================================================== My Applications ==

    public function test_applications_are_recorded_automatically_and_listed_without_any_manual_entry(): void
    {
        $listing = $this->listing(['title' => 'Automatically Tracked Award']);

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertRedirect();
        $application = Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $html = $this->as($this->student)->get(route('applications.mine'))->assertOk()->getContent();

        $this->assertStringContainsString('Automatically Tracked Award', $html);
        $this->assertStringContainsString('#' . $application->application_id, $html);
        $this->assertStringContainsString($application->submitted_at->format('d M Y'), $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString(route('applications.confirmation', $application->application_id), $html);
        $this->assertStringNotContainsString('name="scholarship', $html, 'there is nothing to type in');
    }

    public function test_there_is_no_route_to_record_an_application_by_hand(): void
    {
        $writers = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('POST', $route->methods(), true) && preg_match('#^(apply|applications|my-applications)#', $route->uri()))
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->sort()
            ->values()
            ->all();

        // Submitting an application, one-click apply, saving the details it asks for, and the applicant's own withdrawal -
        // and nothing that lets a person enter where they have applied.
        $this->assertSame(['applications.details', 'applications.quick', 'applications.submit', 'applications.withdraw'], $writers);
    }

    public function test_a_second_application_to_the_same_scholarship_is_still_refused(): void
    {
        $listing = $this->listing();

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertRedirect();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertSessionHas('errorMessage');
        $this->as($this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertRedirect(route('applications.mine'));
        $this->as($this->student)->post(route('applications.details', $listing->opportunity_id), ['profile' => ['province' => 'Harare']])->assertRedirect(route('applications.mine'));

        $this->assertSame(1, Application::where('user_id', $this->student->user_id)->where('opportunity_id', $listing->opportunity_id)->count());
    }

    public function test_after_withdrawing_the_applicant_applies_again_on_the_same_record(): void
    {
        $listing = $this->listing();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement());
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $this->as($this->student)->post(route('applications.withdraw', $application->application_id), ['reason' => 'Changed my mind.'])->assertRedirect();
        $this->assertSame(ApplicationStatus::WITHDRAWN, $application->fresh()->application_status);

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertRedirect();

        $this->assertSame(1, Application::where('opportunity_id', $listing->opportunity_id)->count());
        $this->assertSame(ApplicationStatus::PENDING, $application->fresh()->application_status);
    }

    // ============================================================ history is preserved ==

    public function test_the_application_keeps_what_was_sent_when_the_profile_changes_later(): void
    {
        $this->profile()->forceFill(['province' => null])->save();
        $listing = $this->listing();
        $this->details($listing, ['profile' => ['province' => 'Harare']]);

        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement())->assertRedirect();
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $sent = $application->submitted_snapshot['profile'];

        $this->assertSame('Harare', $sent['province'], 'the detail added on the apply page is in what was sent');
        $this->assertSame('University of Zimbabwe', $sent['institution_name']);

        // The applicant edits their profile afterwards.
        app(ApplicantProfileService::class)->update($this->student, [
            'province' => 'Bulawayo', 'institution_name' => 'Another University', 'biography' => 'Changed.', 'education_level' => EducationLevel::MASTERS,
        ]);

        $this->assertSame($sent, $application->fresh()->submitted_snapshot['profile'], 'the record of the submission is unchanged');

        $page = $this->as($this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();
        $this->assertStringContainsString('University of Zimbabwe', $page);
        $this->assertStringNotContainsString('Another University', $page);
    }

    public function test_the_documents_sent_stay_the_documents_sent_when_the_profile_replaces_them(): void
    {
        $listing = $this->listing();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement());
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $sentPath = ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path');

        app(ApplicantProfileService::class)->storeDocument($this->student, 'transcript', $this->pdf('newer.pdf'));

        $this->assertNotSame($sentPath, $this->profile()->documentPath('transcript'));
        $this->assertSame($sentPath, ApplicationDocument::where('application_id', $application->application_id)->where('type', 'transcript')->value('path'));
    }

    public function test_decisions_and_their_reasons_are_untouched_by_the_new_flow(): void
    {
        $listing = $this->listing();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement());
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();

        $this->as($this->provider)->post('/provider/applications/' . $application->application_id . '/review', ['status' => 'ACCEPTED', 'reason' => 'Strong profile.'])
            ->assertSessionHas('successMessage');

        $application->refresh();
        $this->assertSame(ApplicationStatus::ACCEPTED, $application->application_status);
        $this->assertSame('Strong profile.', $application->decision_reason);

        // An accepted application still blocks a new one, and the apply page says so rather than reopening the form.
        $this->as($this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertRedirect(route('applications.mine'));
    }

    // ============================================================= privacy and access ==

    public function test_an_applicant_reaches_only_their_own_records(): void
    {
        $listing = $this->listing();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement());
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $other = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();
        $otherBefore = ApplicantProfile::where('user_id', $other->user_id)->first()->only(['province', 'institution_name', 'transcript_path']);

        $this->assertNotSame(200, $this->as($other)->get(route('applications.confirmation', $application->application_id))->getStatusCode());

        // /my-documents/{type} has no way to name another person: it is always the signed-in user's own.
        $mine = $this->as($other)->get(route('files.myDocument', 'transcript'));
        $this->assertNotSame(200, $mine->getStatusCode(), 'they hold no transcript of their own and cannot see the other person\'s');

        // Saving details only ever writes to the signed-in applicant's own profile.
        $this->as($other)->post(route('applications.details', $listing->opportunity_id), ['profile' => ['province' => 'Masvingo']]);
        $this->assertSame($otherBefore, ApplicantProfile::where('user_id', $other->user_id)->first()->only(array_keys($otherBefore)));
        $this->assertSame('Harare', $this->profile()->province);
    }

    public function test_a_provider_who_does_not_own_the_listing_cannot_open_the_application_or_its_documents(): void
    {
        $listing = $this->listing();
        $this->as($this->student)->post(route('applications.submit', $listing->opportunity_id), $this->statement());
        $application = Application::where('opportunity_id', $listing->opportunity_id)->firstOrFail();
        $rival = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();

        $this->as($rival)->get(route('provider.applications.show', $application->application_id))->assertForbidden();
        foreach (['files.applicantTranscript', 'files.applicantPassport', 'files.applicantRecommendation'] as $route) {
            $this->as($rival)->get(route($route, $application->application_id))->assertForbidden();
        }
    }

    public function test_the_details_step_is_for_applicants_on_published_listings_only(): void
    {
        $listing = $this->listing();
        $draft = $this->listing(['moderation_status' => OpportunityModerationStatus::PENDING]);
        $data = ['profile' => ['province' => 'Harare']];

        $this->as($this->provider)->post(route('applications.details', $listing->opportunity_id), $data)->assertForbidden();
        $this->as($this->student)->post(route('applications.details', $draft->opportunity_id), $data)->assertNotFound();
        $this->as($this->student)->post(route('applications.details', 999999), $data)->assertNotFound();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post(route('applications.details', $listing->opportunity_id), $data)->assertRedirect(route('login'));
    }

    public function test_uploads_stay_on_the_private_disk_and_are_never_a_public_url(): void
    {
        $this->profile()->forceFill(['passport_path' => null, 'passport_filename' => null])->save();
        $listing = $this->listing();

        $this->details($listing, ['documents' => ['passport' => $this->pdf('id.pdf')]])->assertRedirect();

        $path = $this->profile()->documentPath('passport');
        $page = $this->wizard($listing) . $this->as($this->student)->get(route('applicant.profile'))->getContent();

        $this->assertStringNotContainsString($path, $page, 'no storage path is ever shown');
        $this->assertStringContainsString(route('files.myDocument', 'passport'), $page, 'it is opened through an authorised route');
        $this->assertStringNotContainsString('/storage/', $page);
    }
}
