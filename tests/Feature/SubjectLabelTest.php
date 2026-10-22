<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Opportunity;
use App\Models\Programme;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The subject label on a listing (the form's optional "Subject label for the listing card").
 *
 * It is a headline for the card, the subject filter and keyword search. It is tidied when saved so the filter does not
 * list one subject twice, and it does not decide who qualifies once the listing says who it is open to.
 */
class SubjectLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    private function labelled(string $title, ?string $label): Opportunity
    {
        return Opportunity::create([
            'provider_user_id' => $this->provider->user_id, 'provider_name' => 'Label Provider', 'title' => $title,
            'description' => 'Plain description.', 'education_level' => EducationLevel::UNDERGRADUATE, 'target_field' => $label,
            'funding_type' => 'Full Scholarship', 'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30), 'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED, 'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);
    }

    public function test_keyword_search_reads_the_subject_label(): void
    {
        $this->labelled('Quiet Award', 'Zirconium Metallurgy');
        $this->labelled('Other Award', null);

        $found = Opportunity::query()->matchingFilters(['keyword' => 'Zirconium'])->pluck('title')->all();

        $this->assertSame(['Quiet Award'], $found);
    }

    public function test_the_subject_filter_still_matches_exactly(): void
    {
        $this->labelled('Mining Award', 'Mining Engineering');
        $this->labelled('Law Award', 'Law');

        $this->assertSame(['Law Award'], Opportunity::query()->matchingFilters(['field_of_study' => 'Law'])->pluck('title')->all());
    }

    public function test_the_label_is_tidied_so_the_filter_does_not_list_one_subject_twice(): void
    {
        $this->assertSame('Mining Engineering', Opportunity::tidySubjectArea("  Mining   Engineering \n"));
        $this->assertNull(Opportunity::tidySubjectArea('   '));
        $this->assertNull(Opportunity::tidySubjectArea(['x']));

        $known = \App\Support\FormOptions::FIELDS_OF_STUDY[0];
        $this->assertSame($known, Opportunity::tidySubjectArea(strtolower($known)), 'the catalogue spelling wins');
    }

    public function test_the_label_does_not_decide_who_qualifies_once_the_listing_says_who_it_is_open_to(): void
    {
        $user = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $profile = ApplicantProfile::where('user_id', $user->user_id)->firstOrFail();
        $profile->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null, 'degree_classification' => null])->save();
        ApplicantProgramme::query()->delete();
        ApplicantProgramme::create([
            'profile_id' => $profile->profile_id, 'programme_id' => Programme::where('name', 'BSc Computer Science')->value('id'), 'kind' => 'current',
        ]);

        $listing = $this->labelled('Law Label On A Computing Award', 'Law');
        $listing->forceFill(['education_level' => EducationLevel::MASTERS, 'open_to_all_programmes' => true, 'any_institution' => true])->save();

        $profile = $profile->fresh();
        $fit = new EligibilityResult(
            $listing->fresh(),
            app(EligibilityEvaluator::class)->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)),
            true
        );

        $this->assertTrue($fit->meetsRequirements(), 'a label saying Law does not refuse a Computer Science student');
    }

    public function test_the_form_explains_what_the_label_is_for(): void
    {
        $this->actingAs($this->provider)->get('/opportunities/create')
            ->assertOk()
            ->assertSee('Subject label for the listing card (optional)')
            ->assertSee('who qualifies is set by');
    }
}
