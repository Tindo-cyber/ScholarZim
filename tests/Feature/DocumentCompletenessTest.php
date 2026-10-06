<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profile completeness counts every required document separately.
 *
 * The checklist used to carry one document item (the academic evidence)
 * standing in for the whole set, so an Undergraduate applicant with one of
 * three required uploads could read 100%. requiredDocumentTypes() is the
 * source of truth for which documents count; missingRequiredDocumentTypes()
 * for which are still outstanding.
 */
class DocumentCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private const LABELS = [
        'transcript' => 'Academic Certificate / Proof of Study',
        'passport' => 'ID or passport',
        'recommendation' => 'Recommendation letter',
    ];

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Undergraduate: transcript + ID + recommendation letter.
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->profile()->update([
            'transcript_path' => null,
            'passport_path' => null,
            'recommendation_letter_path' => null,
        ]);
    }

    public function test_an_undergraduate_profile_requires_three_documents(): void
    {
        $this->assertSame(['transcript', 'passport', 'recommendation'], $this->profile()->requiredDocumentTypes());
    }

    public function test_none_of_three_uploaded(): void
    {
        $profile = $this->profile();

        $this->assertLessThan(100, $profile->completionPercentage());
        $this->assertSame(['transcript', 'passport', 'recommendation'], $profile->missingRequiredDocumentTypes());
        $this->assertDocumentItems($profile, ['transcript' => false, 'passport' => false, 'recommendation' => false]);
    }

    public function test_one_of_three_uploaded(): void
    {
        $profile = $this->withDocuments(['transcript']);

        $this->assertLessThan(100, $profile->completionPercentage());
        $this->assertSame(['passport', 'recommendation'], $profile->missingRequiredDocumentTypes());
        $this->assertDocumentItems($profile, ['transcript' => true, 'passport' => false, 'recommendation' => false]);
    }

    public function test_two_of_three_uploaded(): void
    {
        $profile = $this->withDocuments(['transcript', 'passport']);

        $this->assertLessThan(100, $profile->completionPercentage());
        $this->assertSame(['recommendation'], $profile->missingRequiredDocumentTypes());
        $this->assertDocumentItems($profile, ['transcript' => true, 'passport' => true, 'recommendation' => false]);
    }

    public function test_all_three_uploaded(): void
    {
        $profile = $this->withDocuments(['transcript', 'passport', 'recommendation']);

        $this->assertSame(100, $profile->completionPercentage());
        $this->assertSame([], $profile->missingRequiredDocumentTypes());
        $this->assertDocumentItems($profile, ['transcript' => true, 'passport' => true, 'recommendation' => true]);
    }

    /** Each upload moves the percentage; it never jumps straight to 100%. */
    public function test_the_percentage_rises_with_each_upload(): void
    {
        $percentages = [
            $this->profile()->completionPercentage(),
            $this->withDocuments(['transcript'])->completionPercentage(),
            $this->withDocuments(['transcript', 'passport'])->completionPercentage(),
            $this->withDocuments(['transcript', 'passport', 'recommendation'])->completionPercentage(),
        ];

        $this->assertSame($percentages, array_values(array_unique($percentages)));
        $sorted = $percentages;
        sort($sorted);
        $this->assertSame($sorted, $percentages);
        $this->assertSame(100, end($percentages));
    }

    /**
     * The application gate is unchanged: the academic evidence still gates
     * isComplete(), the supporting documents do not - the wizard collects
     * those itself from missingRequiredDocumentTypes().
     */
    public function test_supporting_documents_do_not_change_the_application_gate(): void
    {
        $this->assertFalse($this->profile()->isComplete());
        $this->assertContains(self::LABELS['transcript'], $this->profile()->missingFields());

        $profile = $this->withDocuments(['transcript']);
        $this->assertTrue($profile->isComplete(), 'missing: ' . implode(', ', $profile->missingFields()));
        $this->assertSame([], $profile->missingFields());
    }

    /** The count follows the education level's own document set - not a fixed number. */
    public function test_an_a_level_profile_counts_two_documents(): void
    {
        $tanaka = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $tanaka->user_id)->firstOrFail();
        $profile->update(['recommendation_letter_path' => null]);
        $profile = $profile->fresh();

        $this->assertSame(['results', 'recommendation'], $profile->requiredDocumentTypes());
        $documentItems = array_values(array_filter($profile->completionChecklist(), fn ($i) => $i['anchor'] === 'documents'));
        $this->assertCount(2, $documentItems);
        $this->assertLessThan(100, $profile->completionPercentage());

        $this->actingAs($tanaka)->get('/applicant/profile')
            ->assertOk()
            ->assertSee('2 documents are required for your education level.')
            ->assertSee('1 of 2 required documents uploaded')
            ->assertSee('1 document remaining');
    }

    /** Primary requires no documents: nothing missing, no checklist item, and it still reaches 100% on its academic profile. */
    public function test_primary_requires_no_documents_and_can_still_be_complete(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $kudzai->user_id)->firstOrFail();

        $this->assertSame([], $profile->requiredDocumentTypes());
        $this->assertSame([], $profile->missingRequiredDocumentTypes());
        $this->assertSame([], array_filter($profile->completionChecklist(), fn ($i) => $i['anchor'] === 'documents'));
        $this->assertTrue($profile->isComplete(), 'missing: ' . implode(', ', $profile->missingFields()));
        $this->assertSame(100, $profile->completionPercentage());
    }

    /** Still needs its structured academic results - only the upload requirement went away. */
    public function test_primary_still_needs_its_structured_academic_results(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $kudzai->user_id)->firstOrFail();
        $profile->academicResults()->delete();

        $fresh = $profile->fresh();
        $this->assertContains('Academic results', $fresh->missingFields());
        $this->assertFalse($fresh->isComplete());
    }

    /** The profile page states no document requirement for Primary, and its Documents card is the tier-hidden one. */
    public function test_primary_profile_page_asks_for_no_document(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();

        $html = $this->actingAs($kudzai)->get('/applicant/profile')->assertOk()->getContent();

        $this->assertStringContainsString('id="documents" data-sz-tier-hide="PRIMARY"', $html);
        $this->assertStringNotContainsString('required for your education level', $html);
        $this->assertStringNotContainsString('Required before you can apply', $html);
        $this->assertStringNotContainsString('Results certificate', $html);
    }

    /** Step 1 shows the structured results the profile holds, not "Not provided" because the legacy text column is empty. */
    public function test_the_wizard_shows_recorded_academic_results(): void
    {
        $chipo = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $chipo->user_id)->firstOrFail();
        $this->assertGreaterThan(0, $profile->academicResults()->count());
        $this->assertEmpty($profile->academic_results, 'the legacy text column must be empty for this to prove anything');

        $award = $this->openListing();
        $html = $this->actingAs($chipo)->get('/apply/' . $award->opportunity_id)->assertOk()->getContent();

        $first = $profile->academicResults()->with('subject')->first();
        $this->assertStringContainsString($first->subjectName() . ' ' . $first->result, $html);
        $this->assertDoesNotMatchRegularExpression('/Academic results<\/dt>\s*<dd[^>]*>\s*Not provided/', $html);
    }

    /** The wizard asks a Primary applicant for no required document and still lets them apply. */
    public function test_primary_wizard_requests_no_document_and_application_is_not_refused_for_one(): void
    {
        $kudzai = User::where('email', 'kudzai.marufu@scholarzim.co.zw')->firstOrFail();
        $award = $this->openListing();

        $this->actingAs($kudzai)->get('/apply/' . $award->opportunity_id)
            ->assertOk()
            ->assertSee('No documents are required for your education level.')
            ->assertDontSee('name="documents[', false)
            ->assertDontSee('Results certificate');

        $this->actingAs($kudzai)
            ->post('/apply/' . $award->opportunity_id, [
                'personal_statement' => str_repeat('I want to continue to secondary school. ', 5),
                'confirm' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('applications', [
            'user_id' => $kudzai->user_id,
            'opportunity_id' => $award->opportunity_id,
        ]);
    }

    /** The other levels' wizards still ask for exactly their own missing documents. */
    public function test_other_levels_wizards_still_request_their_missing_documents(): void
    {
        $award = $this->openListing();

        // The academic evidence gates the wizard itself (isComplete()); the
        // supporting documents are what the wizard collects.
        $this->withDocuments(['transcript']);

        $this->actingAs($this->student)->get('/apply/' . $award->opportunity_id)
            ->assertOk()
            ->assertSee('name="documents[passport]"', false)
            ->assertSee('name="documents[recommendation]"', false)
            ->assertDontSee('name="documents[transcript]"', false)
            ->assertDontSee('name="documents[cv]"', false);
    }

    // -------------------------------------------------------------- the page --

    public function test_the_profile_page_states_the_real_count_and_progress(): void
    {
        $this->withDocuments(['transcript']);

        $this->actingAs($this->student)->get('/applicant/profile')
            ->assertOk()
            ->assertDontSee('All four documents')
            ->assertSee('3 documents are required for your education level.')
            ->assertSee('1 of 3 required documents uploaded')
            ->assertSee('2 documents remaining')
            ->assertDontSee('All required documents uploaded.')
            ->assertSee('2 items left');
    }

    public function test_the_profile_page_says_all_uploaded_only_when_they_are(): void
    {
        $this->withDocuments(['transcript', 'passport', 'recommendation']);

        $this->actingAs($this->student)->get('/applicant/profile')
            ->assertOk()
            ->assertSee('3 documents are required for your education level.')
            ->assertSee('All required documents uploaded.')
            ->assertDontSee('documents remaining');
    }

    /** Wording follows the real count: plural for two or more, and no message at all for none. */
    public function test_the_required_count_wording_follows_the_real_count(): void
    {
        $tanaka = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();

        $this->actingAs($tanaka)->get('/applicant/profile')
            ->assertOk()
            ->assertSee('2 documents are required for your education level.')
            ->assertDontSee('1 documents are required')
            ->assertDontSee('All four documents');
    }

    /** The upload message counts what is stored afterwards, one upload at a time. */
    public function test_each_upload_reports_how_many_required_documents_remain(): void
    {
        Storage::fake((string) config('filesystems.default', 'local'));

        $this->actingAs($this->student)
            ->post(route('applicant.profile.documents', 'transcript'), ['document' => $this->pdf()])
            ->assertSessionHas('successMessage', 'Document uploaded. 2 required documents remaining.');

        $this->actingAs($this->student)
            ->post(route('applicant.profile.documents', 'passport'), ['document' => $this->pdf()])
            ->assertSessionHas('successMessage', 'Document uploaded. 1 required document remaining.');

        // An optional document does not change the required count.
        $this->actingAs($this->student)
            ->post(route('applicant.profile.documents', 'cv'), ['document' => $this->pdf()])
            ->assertSessionHas('successMessage', 'Document uploaded. 1 required document remaining.');

        $this->actingAs($this->student)
            ->post(route('applicant.profile.documents', 'recommendation'), ['document' => $this->pdf()])
            ->assertSessionHas('successMessage', 'Document uploaded. All required documents uploaded.');

        $this->assertSame([], $this->profile()->missingRequiredDocumentTypes());
        $this->assertSame(100, $this->profile()->completionPercentage());
    }

    // ------------------------------------------------------------ helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    /** @param  array<int, string>  $types */
    private function withDocuments(array $types): ApplicantProfile
    {
        $profile = $this->profile();

        foreach ($types as $type) {
            $profile->{ApplicantProfile::DOCUMENT_TYPES[$type] . '_path'} = 'profiles/demo/' . $type . '.pdf';
        }

        $profile->save();

        return $profile->fresh();
    }

    /** @param  array<string, bool>  $expected  type => done, in checklist order */
    private function assertDocumentItems(ApplicantProfile $profile, array $expected): void
    {
        $items = array_values(array_filter($profile->completionChecklist(), fn ($i) => $i['anchor'] === 'documents'));

        $this->assertSame(
            array_map(fn ($type) => self::LABELS[$type], array_keys($expected)),
            array_column($items, 'label'),
            'one checklist item per required document'
        );
        $this->assertSame(array_values($expected), array_column($items, 'done'));
    }

    private function openListing(): \App\Models\Opportunity
    {
        return \App\Models\Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->firstOrFail()->user_id,
            'provider_name' => 'Open Provider',
            'title' => 'Open Community Award',
            'description' => 'A listing with no stated requirements.',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'status' => \App\Support\OpportunityStatus::ACTIVE,
            'moderation_status' => \App\Support\OpportunityModerationStatus::APPROVED,
            'submitted_at' => now()->subDay(),
            'created_at' => now(),
        ]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }
}
