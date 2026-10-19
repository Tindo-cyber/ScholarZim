<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Support\AuditAction;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A provider reviewing an application can open every document it was submitted with, and nothing else.
 *
 * Only the results certificate, transcript and Grade 7 slip were ever recorded with an application, so the CV, ID or
 * passport and recommendation letter - all of which the applicant is asked to upload, and told a provider can open -
 * had no record, no route and no row on the review page. Files here are small generated PDFs on a faked private disk.
 */
class ProviderDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    /** type => [profile path column, filename column, route, audit action, label shown to the provider] */
    private const DOCUMENTS = [
        'results' => ['results_certificate_path', 'results_certificate_filename', 'files.applicantResults', AuditAction::VIEW_APPLICANT_RESULTS, 'Results certificate'],
        'transcript' => ['transcript_path', 'transcript_filename', 'files.applicantTranscript', AuditAction::VIEW_APPLICANT_TRANSCRIPT, 'Academic Certificate / Proof of Study'],
        'grade7_slip' => ['grade7_slip_path', 'grade7_slip_filename', 'files.applicantGrade7Slip', AuditAction::VIEW_APPLICANT_RESULTS, 'Grade 7 results slip'],
        'cv' => ['cv_path', 'cv_filename', 'files.applicantCv', AuditAction::VIEW_APPLICANT_DOCUMENT, 'CV / resume'],
        'passport' => ['passport_path', 'passport_filename', 'files.applicantPassport', AuditAction::VIEW_APPLICANT_DOCUMENT, 'ID or passport'],
        'recommendation' => ['recommendation_letter_path', 'recommendation_letter_filename', 'files.applicantRecommendation', AuditAction::VIEW_APPLICANT_DOCUMENT, 'Recommendation letter'],
    ];

    private User $student;

    private User $provider;

    private User $otherProvider;

    private User $admin;

    private Opportunity $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->otherProvider = User::where('email', 'newprovider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();

        $this->listing = Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Doc Provider',
            'title' => 'Document Review Award', 'description' => 'No stated requirements.',
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ helpers --

    private function disk()
    {
        return Storage::disk((string) config('filesystems.default', 'local'));
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** A small PDF on the private disk, carrying a marker so a download can be told apart from another. */
    private function putFile(string $path, string $marker): void
    {
        $this->disk()->put($path, "%PDF-1.4\n% $marker\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    /** The student holds these documents, each file saying which it is. @param array<int, string> $types */
    private function hold(array $types, string $version = 'v1'): void
    {
        $profile = $this->profile();

        foreach (self::DOCUMENTS as $type => [$path, $filename]) {
            if (in_array($type, $types, true)) {
                $this->putFile("profile-docs/$type-$version.pdf", "$type $version");
                $profile->forceFill([$path => "profile-docs/$type-$version.pdf", $filename => "$type-$version.pdf"]);
            } else {
                $profile->forceFill([$path => null, $filename => null]);
            }
        }

        $profile->save();
    }

    private function apply(bool $withAttachment = false): Application
    {
        $attachment = $withAttachment
            ? UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\n% attachment v1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")
            : null;

        return app(ApplicationService::class)->submit(
            $this->listing->opportunity_id,
            $this->student->fresh(),
            ['personal_statement' => str_repeat('A good reason. ', 10)],
            $attachment
        );
    }

    /**
     * An application whose record lacks some documents - as one saved before they were recorded would, or a document
     * the applicant never sent. A normal submission cannot be made without the required ones (ApplicationService),
     * so the records are removed afterwards.
     *
     * @param  array<int, string>  $types
     */
    private function forgetRecorded(Application $application, array $types): void
    {
        ApplicationDocument::where('application_id', $application->application_id)->whereIn('type', $types)->delete();
    }

    private function as(User $user): self
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    private function reviewPage(Application $application, ?User $user = null): string
    {
        return $this->as($user ?? $this->provider)->get(route('provider.applications.show', $application->application_id))->assertOk()->getContent();
    }

    /** Just the documents list, so words like "Not provided" elsewhere on the page do not count. */
    private function documentList(string $html): string
    {
        preg_match('/<ul[^>]*id="application-documents".*?<\/ul>/s', $html, $match);
        $this->assertNotEmpty($match, 'the documents list is on the page');

        return $match[0];
    }

    private function allTypes(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    // ------------------------------------------- each document type, listed and served --

    public function test_each_submitted_document_is_listed_with_a_view_link(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply(withAttachment: true);

        $html = $this->reviewPage($application);
        $list = $this->documentList($html);

        foreach (self::DOCUMENTS as $type => [, , $route, , $label]) {
            $this->assertStringContainsString($label, $html, $type);
            $this->assertStringContainsString(route($route, $application->application_id), $html, "$type has a link");
        }

        $this->assertStringContainsString(route('files.applicationDocument', $application->application_id), $html, 'the attachment has a link');
        $this->assertStringContainsString('statement.pdf', $html, 'named as the applicant named it');
        $this->assertStringNotContainsString('Not provided', $list);
    }

    public function test_each_endpoint_returns_the_file_that_was_submitted(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply(withAttachment: true);

        foreach (self::DOCUMENTS as $type => [, , $route]) {
            $body = $this->as($this->provider)->get(route($route, $application->application_id))->assertOk()->streamedContent();

            $this->assertStringContainsString("$type v1", $body, $type);
        }

        $this->assertStringContainsString('attachment v1', $this->as($this->provider)->get(route('files.applicationDocument', $application->application_id))->assertOk()->streamedContent());
    }

    public function test_every_type_the_applicant_can_upload_is_recorded_with_the_application(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        $this->assertEqualsCanonicalizing($this->allTypes(), ApplicationDocument::where('application_id', $application->application_id)->pluck('type')->all());
    }

    // ---------------------------------------------------------- missing documents --

    public function test_a_document_that_was_not_submitted_has_no_link_and_its_endpoint_is_a_clean_404(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();
        $this->forgetRecorded($application, ['recommendation']);

        $html = $this->reviewPage($application);

        $this->assertStringContainsString(route('files.applicantTranscript', $application->application_id), $html);
        $this->assertStringContainsString(route('files.applicantPassport', $application->application_id), $html);

        foreach (['files.applicantCv', 'files.applicantResults', 'files.applicantGrade7Slip', 'files.applicantRecommendation'] as $route) {
            $this->assertStringNotContainsString(route($route, $application->application_id), $html, "$route is not offered");
            $this->as($this->provider)->get(route($route, $application->application_id))->assertNotFound();
        }
    }

    public function test_a_document_the_level_asks_for_and_was_not_sent_says_not_provided(): void
    {
        // An undergraduate is asked for a transcript, a passport and a recommendation letter.
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();
        $this->forgetRecorded($application, ['passport', 'recommendation']);

        $html = $this->reviewPage($application);
        $list = $this->documentList($html);

        $this->assertStringContainsString('Not provided', $list);
        $this->assertStringContainsString('Recommendation letter', $list);
        $this->assertStringContainsString('ID or passport', $list);
        $this->assertStringNotContainsString(route('files.applicantRecommendation', $application->application_id), $html);
    }

    public function test_a_recorded_file_that_has_gone_from_storage_is_unavailable_not_a_dead_link(): void
    {
        $this->hold(['transcript', 'cv', 'passport', 'recommendation']);
        $application = $this->apply();
        $this->disk()->delete('profile-docs/cv-v1.pdf');

        $html = $this->reviewPage($application);

        $this->assertStringContainsString('File unavailable', $html);
        $this->assertStringNotContainsString(route('files.applicantCv', $application->application_id), $html);
        $this->assertStringContainsString(route('files.applicantPassport', $application->application_id), $html, 'the others are unaffected');
        $this->as($this->provider)->get(route('files.applicantCv', $application->application_id))->assertNotFound();
    }

    public function test_the_page_never_shows_a_storage_path(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply(withAttachment: true);

        $html = $this->reviewPage($application);

        $this->assertStringNotContainsString('profile-docs/', $html);
        $this->assertStringNotContainsString((string) $application->fresh()->document_path, $html, 'the attachment path');
        $this->assertStringNotContainsString(storage_path(), $html);
        $this->assertStringNotContainsString('filesystems', $html);
    }

    // ------------------------------------------------------- snapshot semantics --

    public function test_replacing_a_document_after_applying_does_not_change_what_the_provider_opens(): void
    {
        $this->hold(['transcript', 'cv', 'passport', 'recommendation']);
        $application = $this->apply();

        $this->hold(['transcript', 'cv', 'passport', 'recommendation'], 'v2');

        $this->assertStringContainsString('cv v1', $this->as($this->provider)->get(route('files.applicantCv', $application->application_id))->assertOk()->streamedContent());
        $this->assertStringContainsString('recommendation v1', $this->as($this->provider)->get(route('files.applicantRecommendation', $application->application_id))->assertOk()->streamedContent());
    }

    public function test_a_document_uploaded_only_after_applying_is_not_shown(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();

        $this->hold(['transcript', 'passport', 'recommendation', 'cv'], 'v2');

        $this->assertStringNotContainsString(route('files.applicantCv', $application->application_id), $this->reviewPage($application));
        $this->as($this->provider)->get(route('files.applicantCv', $application->application_id))->assertNotFound();
    }

    public function test_an_application_older_than_the_new_records_does_not_borrow_a_cv_uploaded_since(): void
    {
        $this->hold(['transcript', 'cv', 'passport', 'recommendation']);
        $application = $this->apply();

        // As an application saved before the CV, ID and letter were recorded: it holds the transcript record only.
        ApplicationDocument::where('application_id', $application->application_id)->whereIn('type', ['cv', 'passport', 'recommendation'])->delete();

        $html = $this->reviewPage($application);

        $this->assertStringContainsString(route('files.applicantTranscript', $application->application_id), $html);
        foreach (['files.applicantCv', 'files.applicantPassport', 'files.applicantRecommendation'] as $route) {
            $this->assertStringNotContainsString(route($route, $application->application_id), $html);
            $this->as($this->provider)->get(route($route, $application->application_id))->assertNotFound();
        }
    }

    public function test_what_was_asked_is_judged_at_the_level_the_applicant_applied_at(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();
        $this->forgetRecorded($application, ['passport', 'recommendation']);

        // After applying, the profile moves to a level that asks for different documents.
        ApplicantProfile::where('user_id', $application->user_id)->update(['education_level' => \App\Support\EducationLevel::O_LEVEL]);

        $list = $this->documentList($this->reviewPage($application));

        $this->assertStringContainsString('ID or passport', $list, 'asked of an undergraduate, and not sent');
        $this->assertStringContainsString('Recommendation letter', $list);
        $this->assertStringNotContainsString('Results certificate', $list, 'an O-Level certificate was never asked of someone who applied as an undergraduate');
    }

    public function test_an_application_with_no_record_of_its_level_does_not_guess_what_was_asked(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();
        ApplicationDocument::where('application_id', $application->application_id)->delete();
        $application->forceFill(['submitted_snapshot' => null])->save();

        $list = $this->documentList($this->reviewPage($application));

        $this->assertStringNotContainsString('ID or passport', $list);
        $this->assertStringNotContainsString('Recommendation letter', $list);
    }

    public function test_a_document_put_on_the_profile_after_an_old_application_was_sent_is_not_shown_as_part_of_it(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();
        // As one made before documents were recorded: nothing recorded, and only the profile to go on.
        ApplicationDocument::where('application_id', $application->application_id)->delete();
        Application::whereKey($application->application_id)->update(['submitted_at' => now()->subDays(3)]);

        ApplicantProfile::where('user_id', $application->user_id)->update(['transcript_uploaded_at' => now()->subDays(10)]);
        $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))->assertOk();

        ApplicantProfile::where('user_id', $application->user_id)->update(['transcript_uploaded_at' => now()->subDay()]);
        $this->as($this->provider)->get(route('files.applicantTranscript', $application->application_id))->assertNotFound();
        $this->assertStringNotContainsString(route('files.applicantTranscript', $application->application_id), $this->reviewPage($application));
    }

    // --------------------------------------------------------------- authorisation --

    public function test_a_different_provider_cannot_open_the_page_or_any_document(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply(withAttachment: true);

        $this->as($this->otherProvider)->get(route('provider.applications.show', $application->application_id))->assertForbidden();

        foreach (self::DOCUMENTS as $type => [, , $route]) {
            $this->as($this->otherProvider)->get(route($route, $application->application_id))->assertForbidden();
        }

        $this->as($this->otherProvider)->get(route('files.applicationDocument', $application->application_id))->assertForbidden();
    }

    public function test_changing_the_application_id_reaches_nothing(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        foreach ([$application->application_id + 1, $application->application_id + 100, 999999] as $id) {
            foreach (self::DOCUMENTS as [, , $route]) {
                $this->as($this->provider)->get(route($route, $id))->assertNotFound();
            }
        }
    }

    public function test_the_applicant_cannot_use_the_provider_routes(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        foreach (self::DOCUMENTS as [, , $route]) {
            $this->as($this->student)->get(route($route, $application->application_id))->assertForbidden();
        }
    }

    public function test_the_applicant_keeps_their_own_access(): void
    {
        $this->hold(['cv', 'transcript', 'passport', 'recommendation']);
        $application = $this->apply(withAttachment: true);

        $this->assertStringContainsString('attachment v1', $this->as($this->student)->get(route('files.applicationDocument', $application->application_id))->assertOk()->streamedContent());
        $this->assertStringContainsString('cv v1', $this->as($this->student)->get(route('files.myDocument', 'cv'))->assertOk()->streamedContent());
    }

    public function test_another_applicant_cannot_open_the_attachment(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply(withAttachment: true);
        $other = User::where('email', 'chipo.ncube@scholarzim.co.zw')->firstOrFail();

        $response = $this->as($other)->get(route('files.applicationDocument', $application->application_id));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_an_administrator_has_no_route_to_an_applicants_documents(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply(withAttachment: true);

        foreach (self::DOCUMENTS as [, , $route]) {
            $this->as($this->admin)->get(route($route, $application->application_id))->assertForbidden();
        }

        $this->assertNotSame(200, $this->as($this->admin)->get(route('files.applicationDocument', $application->application_id))->getStatusCode());
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        foreach (self::DOCUMENTS as [, , $route]) {
            $this->get(route($route, $application->application_id))->assertRedirect(route('login'));
        }
    }

    // ------------------------------------------------------------------- audit --

    public function test_every_provider_opening_of_a_sensitive_document_is_audited(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        foreach (self::DOCUMENTS as $type => [, , $route, $action]) {
            $before = AuditLog::where('action', $action)->where('actor_email', $this->provider->email)->count();

            $this->as($this->provider)->get(route($route, $application->application_id))->assertOk();

            $this->assertSame($before + 1, AuditLog::where('action', $action)->where('actor_email', $this->provider->email)->count(), $type);
        }
    }

    public function test_a_refused_or_missing_request_writes_no_download_audit(): void
    {
        $this->hold(['transcript', 'passport', 'recommendation']);
        $application = $this->apply();

        $this->as($this->otherProvider)->get(route('files.applicantTranscript', $application->application_id))->assertForbidden();
        $this->as($this->provider)->get(route('files.applicantCv', $application->application_id))->assertNotFound();

        $this->assertSame(0, AuditLog::where('action', AuditAction::VIEW_APPLICANT_TRANSCRIPT)->count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::VIEW_APPLICANT_DOCUMENT)->count());
    }

    public function test_the_audit_line_names_the_document_not_its_path(): void
    {
        $this->hold(['cv', 'transcript', 'passport', 'recommendation']);
        $application = $this->apply();

        $this->as($this->provider)->get(route('files.applicantCv', $application->application_id))->assertOk();

        $entry = AuditLog::where('action', AuditAction::VIEW_APPLICANT_DOCUMENT)->firstOrFail();

        $this->assertStringContainsString('cv', (string) $entry->details);
        $this->assertStringNotContainsString('profile-docs', (string) $entry->details);
    }

    // ------------------------------------------------------------------- private --

    public function test_the_files_are_only_ever_served_through_the_authorised_routes(): void
    {
        $this->hold($this->allTypes());
        $application = $this->apply();

        // Nothing under the private disk is reachable as a public URL: the response is a download, not a redirect to storage.
        $this->as($this->provider);
        $response = $this->get(route('files.applicantCv', $application->application_id))->assertOk();

        $this->assertNull($response->headers->get('Location'));
        $this->assertStringNotContainsString('storage/', (string) $response->headers->get('Content-Disposition'));
    }
}
