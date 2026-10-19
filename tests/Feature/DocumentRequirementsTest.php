<?php

namespace Tests\Feature;

use App\Exceptions\DocumentsRequired;
use App\Models\ApplicantProfile;
use App\Models\DocumentFile;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicantProfileService;
use App\Services\ApplicationService;
use App\Support\DocumentChecklist;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One list of required documents, and every place that shows or enforces it agreeing with it.
 *
 * The profile page, the apply page, the submission check, the completion checklist and the provider's review page all
 * read ApplicantProfile::documentChecklist(). Before it, the profile page counted the level's required documents while
 * drawing a control for a fourth (the optional CV), the wizard recomputed the list for the scholarship, and one-click
 * Apply enforced only the academic certificate - so "required" meant three different things depending on where you were.
 *
 * No count is written into these tests as a magic number: each expectation is the rule for that level (see
 * ApplicantProfile::documentRequirementsFor), and the four-document case is a synthetic checklist, because no education
 * level currently requires four.
 */
class DocumentRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->setLevel(EducationLevel::UNDERGRADUATE);
        $this->clearDocuments();
    }

    // ------------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function setLevel(string $level): void
    {
        $this->profile()->forceFill(['education_level' => $level])->save();
    }

    private function clearDocuments(): void
    {
        $profile = $this->profile();

        foreach (ApplicantProfile::DOCUMENT_TYPES as $prefix) {
            $profile->forceFill([$prefix . '_path' => null, $prefix . '_filename' => null]);
        }

        $profile->save();
    }

    private function file(string $name = 'certificate.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% $name\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    private function upload(string $type, string $name = 'certificate.pdf'): void
    {
        app(ApplicantProfileService::class)->storeDocument($this->student, $type, $this->file($name));
    }

    private function listing(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Docs Provider', 'title' => 'Documents Award ' . uniqid(),
            'description' => 'No stated requirements.', 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(), 'created_at' => now(),
        ], $overrides));
    }

    private function profilePage(): string
    {
        return $this->actingAs($this->student)->get('/applicant/profile')->assertOk()->getContent();
    }

    /** Just the Documents card, so words elsewhere on the page do not count. */
    private function documentsCard(string $html): string
    {
        preg_match('/<div class="card" id="documents".*?<p class="form-text mb-0">.*?<\/p>\s*<\/div>\s*<\/div>/s', $html, $match);
        $this->assertNotEmpty($match, 'the Documents card is on the page');

        return $match[0];
    }

    // ------------------------------------------------ the rule for each level --

    /** @return array<string, array{0: string, 1: array<int, string>}> */
    public static function levels(): array
    {
        return [
            'primary asks for nothing in general' => [EducationLevel::PRIMARY, []],
            'o-level' => [EducationLevel::O_LEVEL, ['results', 'recommendation']],
            'a-level' => [EducationLevel::A_LEVEL, ['results', 'recommendation']],
            'certificate' => [EducationLevel::CERTIFICATE, ['transcript', 'passport', 'recommendation']],
            'diploma' => [EducationLevel::DIPLOMA, ['transcript', 'passport', 'recommendation']],
            'undergraduate' => [EducationLevel::UNDERGRADUATE, ['transcript', 'passport', 'recommendation']],
            'postgraduate' => [EducationLevel::POSTGRADUATE, ['transcript', 'recommendation']],
            'masters' => [EducationLevel::MASTERS, ['transcript', 'recommendation']],
            'phd' => [EducationLevel::PHD, ['transcript', 'recommendation']],
        ];
    }

    #[DataProvider('levels')]
    public function test_the_checklist_requires_what_the_level_requires(string $level, array $expected): void
    {
        $this->setLevel($level);
        $checklist = $this->profile()->documentChecklist();

        $this->assertSame($expected, $checklist->requiredTypes());
        $this->assertSame(count($expected), $checklist->requiredCount(), 'the count is the length of the list, not a constant');
        $this->assertSame($expected, $this->profile()->requiredDocumentTypes(), 'the old accessor reads the same list');
    }

    #[DataProvider('levels')]
    public function test_nothing_uploaded_means_every_required_document_is_missing(string $level, array $expected): void
    {
        $this->setLevel($level);
        $checklist = $this->profile()->documentChecklist();

        $this->assertSame($expected, $checklist->missingTypes());
        $this->assertSame(0, $checklist->uploadedCount());
        $this->assertSame($expected === [], $checklist->isComplete());
    }

    public function test_a_scholarship_can_add_a_requirement_the_level_does_not_have(): void
    {
        $this->setLevel(EducationLevel::PRIMARY);
        $proof = $this->listing(['requires_results_certificate' => true]);
        $plain = $this->listing();

        $this->assertSame([], $this->profile()->documentChecklist($plain)->requiredTypes());
        $this->assertSame(['grade7_slip'], $this->profile()->documentChecklist($proof)->requiredTypes());
        $this->assertSame('scholarship', $this->profile()->documentChecklist($proof)->required()[0]['reason']);
    }

    public function test_an_award_asking_for_proof_of_results_adds_no_second_copy_of_the_level_document(): void
    {
        $proof = $this->listing(['requires_results_certificate' => true]);

        $this->assertSame($this->profile()->documentChecklist()->requiredTypes(), $this->profile()->documentChecklist($proof)->requiredTypes());
    }

    // --------------------------------------------------- counts are data, not numbers --

    public function test_the_counts_and_sentences_follow_whatever_the_list_holds_including_four(): void
    {
        $item = fn (string $type, bool $required, bool $present) => [
            'type' => $type, 'label' => ucfirst($type), 'required' => $required, 'reason' => $required ? 'level' : null,
            'group' => 'other', 'present' => $present, 'filename' => null, 'uploaded_at' => null,
        ];

        $four = new DocumentChecklist([$item('a', true, true), $item('b', true, false), $item('c', true, false), $item('d', true, false), $item('extra', false, true)]);

        $this->assertSame(4, $four->requiredCount());
        $this->assertSame(1, $four->uploadedCount(), 'the optional one on file is not counted');
        $this->assertSame(3, $four->missingCount());
        $this->assertSame('4 documents are required for your education level.', $four->requiredSentence());
        $this->assertSame('1 of 4 required documents uploaded - 3 documents remaining.', $four->progressSentence());
        $this->assertFalse($four->isComplete());

        $one = new DocumentChecklist([$item('a', true, false)]);
        $this->assertSame('1 document is required for your education level.', $one->requiredSentence());
        $this->assertSame('0 of 1 required documents uploaded - 1 document remaining.', $one->progressSentence());

        $none = new DocumentChecklist([$item('x', false, false)]);
        $this->assertNull($none->requiredSentence());
        $this->assertNull($none->progressSentence());
        $this->assertTrue($none->isComplete());
    }

    // ---------------------------------------------- uploaded, missing, complete --

    public function test_each_upload_moves_the_counts_and_all_uploaded_is_only_said_when_it_is_true(): void
    {
        $steps = [];

        foreach (['transcript', 'passport', 'recommendation'] as $type) {
            $steps[] = [$this->profile()->documentChecklist()->uploadedCount(), $this->profile()->documentChecklist()->isComplete()];
            $this->upload($type);
        }

        $this->assertSame([[0, false], [1, false], [2, false]], $steps);
        $this->assertSame(3, $this->profile()->documentChecklist()->uploadedCount());
        $this->assertTrue($this->profile()->documentChecklist()->isComplete());
    }

    public function test_the_profile_page_never_says_all_uploaded_while_one_is_missing(): void
    {
        $this->upload('transcript');
        $this->upload('passport');

        $card = $this->documentsCard($this->profilePage());

        $this->assertStringContainsString('3 documents are required for your education level.', $card);
        $this->assertStringContainsString('2 of 3 required documents uploaded - 1 document remaining.', $card);
        $this->assertStringNotContainsString('All required documents uploaded.', $card);
    }

    public function test_the_profile_page_says_all_uploaded_once_they_are(): void
    {
        foreach (['transcript', 'passport', 'recommendation'] as $type) {
            $this->upload($type);
        }

        $card = $this->documentsCard($this->profilePage());

        $this->assertStringContainsString('All required documents uploaded.', $card);
        $this->assertStringNotContainsString('remaining', $card);
    }

    public function test_the_status_is_read_from_what_is_stored_so_a_refresh_cannot_disagree(): void
    {
        // "Uploaded 1 second ago" must not tick over to 2 between the two reads.
        $this->freezeTime();

        $this->upload('transcript');
        $first = $this->documentsCard($this->profilePage());
        $second = $this->documentsCard($this->profilePage());

        $this->assertSame($first, $second);
        $this->assertStringContainsString('1 of 3 required documents uploaded', $first);
    }

    // ------------------------------------ the optional CV is a control, not a requirement --

    public function test_the_cv_is_listed_as_optional_and_is_never_counted_as_required(): void
    {
        $checklist = $this->profile()->documentChecklist();

        $this->assertCount(4, $checklist->items, 'four upload controls');
        $this->assertSame(3, $checklist->requiredCount(), 'three of them required');
        $this->assertSame(['cv'], array_column($checklist->optional(), 'type'));

        $card = $this->documentsCard($this->profilePage());
        $this->assertStringContainsString('3 documents are required', $card);
        $this->assertMatchesRegularExpression('/id="document-cv".*?Optional/s', $card);
    }

    public function test_uploading_the_optional_cv_does_not_change_the_required_progress(): void
    {
        $this->upload('cv');

        $checklist = $this->profile()->documentChecklist();

        $this->assertSame(0, $checklist->uploadedCount());
        $this->assertSame(3, $checklist->missingCount());
        $this->assertStringContainsString('0 of 3 required documents uploaded', $this->documentsCard($this->profilePage()));
    }

    public function test_the_same_requirement_is_never_counted_twice(): void
    {
        foreach ([EducationLevel::O_LEVEL, EducationLevel::UNDERGRADUATE, EducationLevel::MASTERS] as $level) {
            $this->setLevel($level);
            $types = $this->profile()->documentChecklist()->requiredTypes();

            $this->assertSame($types, array_values(array_unique($types)), $level);
            $items = array_column($this->profile()->documentChecklist()->items, 'type');
            $this->assertSame($items, array_values(array_unique($items)), "$level lists each document once");
        }
    }

    // ------------------------------------- repeated uploads and duplicate records --

    public function test_uploading_the_same_document_again_replaces_it_and_counts_once(): void
    {
        $this->upload('transcript', 'first.pdf');
        $this->upload('transcript', 'second.pdf');
        $this->upload('transcript', 'third.pdf');

        $profile = $this->profile();

        $this->assertSame(1, $profile->documentChecklist()->uploadedCount());
        $this->assertNotNull($profile->documentPath('transcript'), 'one file is held, the latest');
        $this->assertSame(1, count(array_filter($profile->documentChecklist()->items, fn ($i) => $i['type'] === 'transcript')));
    }

    public function test_a_duplicate_upload_record_is_refused_and_cannot_change_the_count(): void
    {
        $this->upload('transcript');
        $path = $this->profile()->documentPath('transcript');
        $row = DocumentFile::query()->where('path', $path)->firstOrFail();

        // One metadata row per stored file, enforced by the database: a copy cannot be inserted to count a document twice.
        try {
            DocumentFile::create($row->only($row->getFillable()));
            $this->fail('a second record for the same stored file must be refused');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, DocumentFile::query()->where('path', $path)->count());
        $this->assertSame(1, $this->profile()->documentChecklist()->uploadedCount());
        $this->assertStringContainsString('1 of 3 required documents uploaded', $this->documentsCard($this->profilePage()));
    }

    // -------------------------------------------- a profile change changes the list --

    public function test_changing_the_education_level_changes_the_requirements_everywhere(): void
    {
        $this->upload('transcript');
        $this->upload('recommendation');

        $this->assertStringContainsString('3 documents are required', $this->documentsCard($this->profilePage()));

        $this->setLevel(EducationLevel::MASTERS);
        $card = $this->documentsCard($this->profilePage());

        $this->assertStringContainsString('2 documents are required for your education level.', $card);
        $this->assertStringContainsString('All required documents uploaded.', $card, 'a transcript and a letter are all a postgraduate is asked for');

        $this->setLevel(EducationLevel::A_LEVEL);
        $card = $this->documentsCard($this->profilePage());

        $this->assertStringContainsString('2 documents are required', $card);
        $this->assertStringContainsString('1 of 2 required documents uploaded - 1 document remaining.', $card, 'a certificate is now needed and the transcript no longer counts');
    }

    public function test_the_card_asks_for_the_certificate_that_fits_the_level_and_not_the_other(): void
    {
        $this->setLevel(EducationLevel::A_LEVEL);
        $school = $this->documentsCard($this->profilePage());
        $this->assertStringContainsString('Results certificate', $school);
        $this->assertStringNotContainsString('Academic Certificate / Proof of Study', $school);

        $this->setLevel(EducationLevel::UNDERGRADUATE);
        $tertiary = $this->documentsCard($this->profilePage());
        $this->assertStringContainsString('Academic Certificate / Proof of Study', $tertiary);
        $this->assertStringNotContainsString('Results certificate', $tertiary);
    }

    // ----------------------------------------------- every place agrees with the list --

    public function test_the_page_the_wizard_the_gate_and_the_provider_all_ask_for_the_same_documents(): void
    {
        $this->upload('transcript');
        $listing = $this->listing();

        $missing = $this->profile()->documentChecklist($listing)->missing();
        $this->assertSame(['passport', 'recommendation'], array_column($missing, 'type'));
        $labels = array_column($missing, 'label');

        // The profile page: counts exactly those as remaining.
        $this->assertStringContainsString('2 documents remaining', $this->documentsCard($this->profilePage()));

        // The apply page: an upload box for exactly those, and for nothing else.
        $wizard = $this->actingAs($this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertOk()->getContent();
        $this->assertStringContainsString('name="documents[passport]"', $wizard);
        $this->assertStringContainsString('name="documents[recommendation]"', $wizard);
        $this->assertStringNotContainsString('name="documents[transcript]"', $wizard);
        $this->assertStringNotContainsString('name="documents[cv]"', $wizard);
        $this->assertStringContainsString('2 documents are still needed', $wizard);

        // The submission check: refuses, naming exactly those.
        try {
            app(ApplicationService::class)->quickApply($listing->opportunity_id, $this->student->fresh());
            $this->fail('the required documents are missing');
        } catch (DocumentsRequired $e) {
            $this->assertSame($labels, $e->labels);
            $this->assertStringContainsString('ID or passport and Recommendation letter', $e->getMessage());
        }

        // Once uploaded they all agree the other way.
        $this->upload('passport');
        $this->upload('recommendation');

        $this->assertStringContainsString('All required documents uploaded.', $this->documentsCard($this->profilePage()));
        $this->actingAs($this->student)->get(route('applications.wizard', $listing->opportunity_id))->assertOk()
            ->assertSee('All your required documents are already on file', false)
            ->assertDontSee('name="documents[passport]"', false);
        $this->assertSame([], $this->profile()->documentChecklist($listing)->missing());
    }

    public function test_one_click_apply_cannot_skip_a_required_document(): void
    {
        $this->upload('transcript');
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/apply/' . $listing->opportunity_id . '/quick')
            ->assertRedirect(route('applications.wizard', $listing->opportunity_id))
            ->assertSessionHas('errorMessage');

        $this->assertStringContainsString('ID or passport', session('errorMessage'));
        $this->assertDatabaseMissing('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    public function test_a_direct_submission_cannot_skip_a_required_document_either(): void
    {
        $this->upload('transcript');
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/apply/' . $listing->opportunity_id, [
            'personal_statement' => str_repeat('A good reason. ', 10), 'confirm' => '1',
        ])->assertSessionHasErrors(['documents.passport', 'documents.recommendation']);

        $this->assertDatabaseMissing('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    public function test_the_applicant_applies_once_every_required_document_is_on_file_without_the_optional_one(): void
    {
        foreach (['transcript', 'passport', 'recommendation'] as $type) {
            $this->upload($type);
        }
        $listing = $this->listing();

        $this->actingAs($this->student)->post('/apply/' . $listing->opportunity_id . '/quick')->assertRedirect();

        $this->assertDatabaseHas('applications', ['user_id' => $this->student->user_id, 'opportunity_id' => $listing->opportunity_id]);
    }

    public function test_the_completion_checklist_lists_the_same_required_documents(): void
    {
        $labels = array_column($this->profile()->documentChecklist()->required(), 'label');
        $fromChecklist = array_column($this->profile()->completionChecklist(), 'label');

        foreach ($labels as $label) {
            $this->assertContains($label, $fromChecklist);
        }
        $this->assertNotContains('CV / resume', $fromChecklist, 'an optional document is not a checklist item');
    }

    // -------------------------------------------------- the upload instruction --

    public function test_the_instruction_is_short_and_says_only_what_the_applicant_must_do(): void
    {
        $card = $this->documentsCard($this->profilePage());

        $this->assertStringContainsString('Upload the required documents listed below.', $card);
        $this->assertStringContainsString('Upload clear, readable copies. Existing documents will be reused when they meet the requirements.', $card);
        $this->assertStringNotContainsString('academic certificates only', $card, 'it must not suggest a certificate is all that is needed');
        $this->assertSame(1, substr_count($card, 'Upload the required documents listed below.'), 'said once, for the whole card');
    }

    public function test_the_card_no_longer_explains_scholarfit_or_internal_processing(): void
    {
        $card = $this->documentsCard($this->profilePage());

        foreach (['ScholarFit', 'academic evidence', 'O-Level/A-Level', 'rely on', 'Most providers expect one', 'Used to confirm your identity', 'Supporting reference from'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $card, $phrase);
        }
    }

    public function test_every_document_keeps_its_own_label_so_a_certificate_does_not_stand_in_for_another(): void
    {
        $card = $this->documentsCard($this->profilePage());

        foreach (['Academic Certificate / Proof of Study', 'ID or passport', 'Recommendation letter', 'CV / resume'] as $label) {
            $this->assertStringContainsString($label, $card);
        }

        // One instruction at the top covers every document below; the two groups are headed apart.
        $this->assertLessThan(strpos($card, 'Academic certificates'), strpos($card, 'Upload the required documents listed below.'));
        $this->assertLessThan(strpos($card, 'Other documents'), strpos($card, 'Academic Certificate / Proof of Study'));
        $this->assertGreaterThan(strpos($card, 'Other documents'), strpos($card, 'ID or passport'));
    }

    public function test_replacing_and_privacy_are_said_once_and_briefly(): void
    {
        $card = $this->documentsCard($this->profilePage());

        $this->assertSame(1, substr_count($card, 'A new upload replaces the current file.'));
        $this->assertSame(1, substr_count($card, 'Only you and providers you apply to can open your documents.'));
        $this->assertStringContainsString('PDF, Word, JPG or PNG.', $card);
    }

    public function test_a_primary_pupil_sees_no_documents_card_and_a_short_slip_note(): void
    {
        $this->setLevel(EducationLevel::PRIMARY);
        $html = $this->profilePage();

        $this->assertStringContainsString('id="documents" data-sz-tier-hide="PRIMARY"', $html);
        $slip = substr($html, (int) strpos($html, 'id="grade7-slip-card"'), 2500);
        $this->assertStringContainsString('A new upload replaces the current one.', $slip);
        $this->assertStringNotContainsString('ScholarFit', $slip);
    }
}
