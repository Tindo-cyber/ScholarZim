<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\Opportunity;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\User;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Services\ScholarFit\EligibilityResult;
use App\Services\ScholarFit\RequirementOutcome;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ScholarFit reads the field tree: a listing open to a field includes the fields beneath it, a neighbouring field is a
 * different subject, and a programme only filed under a broader heading is never treated as an exact match for something
 * more specific than where it is filed.
 */
class HierarchicalFieldMatchingTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->useFullCatalogue();
        app(\App\Services\Catalogue\CatalogueImporter::class)->importFile('fields', database_path('seeders/data/fields.csv'));  // the field list only, not the programmes

        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->profile()->forceFill(['education_level' => EducationLevel::UNDERGRADUATE, 'field_of_study' => null, 'degree_classification' => null])->save();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function field(string $code): Field
    {
        return Field::where('code', $code)->firstOrFail();
    }

    /** The student is on a programme filed under this field code. */
    private function enrolledIn(string $fieldCode): void
    {
        $programme = Programme::create([
            'name' => 'BCom Test Programme ' . $fieldCode, 'education_level' => EducationLevel::UNDERGRADUATE,
            'field_id' => $this->field($fieldCode)->id, 'is_active' => true, 'status' => Programme::APPROVED,
        ]);

        ApplicantProgramme::query()->delete();
        ApplicantProgramme::create(['profile_id' => $this->profile()->profile_id, 'programme_id' => $programme->id, 'kind' => 'current']);
    }

    private function scopeOutcome(array $fieldCodes): ?RequirementOutcome
    {
        $listing = Opportunity::create([
            'provider_user_id' => User::where('email', 'provider@scholarzim.co.zw')->value('user_id'),
            'provider_name' => 'Scope Provider', 'title' => 'A Bursary', 'description' => 'A listing used to exercise field matching.',
            'education_level' => EducationLevel::MASTERS, 'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe', 'target_country' => 'Zimbabwe', 'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE, 'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(), 'created_at' => Carbon::now(),
        ]);

        foreach ($fieldCodes as $code) {
            OpportunityScope::create(['opportunity_id' => $listing->opportunity_id, 'field_id' => $this->field($code)->id]);
        }

        $profile = $this->profile()->fresh();
        $fit = new EligibilityResult($listing->fresh(), app(EligibilityEvaluator::class)->evaluate($profile, $listing->fresh(), AcademicRecord::fromProfile($profile)), true);

        foreach ($fit->outcomes as $outcome) {
            if ($outcome->type === RequirementOutcome::TYPE_PROGRAMME_SCOPE) {
                return $outcome;
            }
        }

        return null;
    }

    public function test_a_programme_in_a_detailed_field_matches_a_listing_for_exactly_that_field(): void
    {
        $this->enrolledIn('0412');

        $outcome = $this->scopeOutcome(['0412']);

        $this->assertTrue($outcome->passed);
        $this->assertSame(RequirementOutcome::FIT_NARROW_FIELD, $outcome->fit);
    }

    public function test_a_listing_for_a_narrow_field_includes_the_detailed_fields_beneath_it(): void
    {
        $this->enrolledIn('0412');

        $this->assertTrue($this->scopeOutcome(['041'])->passed);
    }

    public function test_a_listing_for_an_old_broad_field_still_includes_everything_under_it(): void
    {
        $this->enrolledIn('0412');

        $outcome = $this->scopeOutcome(['04']);

        $this->assertTrue($outcome->passed, 'listings saved against the broad field keep working');
        $this->assertSame(RequirementOutcome::FIT_BROAD_FIELD, $outcome->fit);
    }

    public function test_sibling_fields_are_different_subjects(): void
    {
        $this->enrolledIn('0412');   // Finance, banking and insurance

        $this->assertFalse($this->scopeOutcome(['0411'])->passed, 'accounting is not finance');
        $this->assertFalse($this->scopeOutcome(['0414'])->passed, 'marketing is not finance');
    }

    public function test_business_and_law_share_a_heading_but_not_a_match(): void
    {
        $this->enrolledIn('0412');
        $this->assertFalse($this->scopeOutcome(['042'])->passed);
        $this->assertFalse($this->scopeOutcome(['0421'])->passed);

        $this->enrolledIn('0421');
        $this->assertFalse($this->scopeOutcome(['041'])->passed);
        $this->assertTrue($this->scopeOutcome(['042'])->passed);
    }

    public function test_a_programme_only_filed_under_a_broader_field_is_not_an_exact_match_for_a_specific_one(): void
    {
        $this->enrolledIn('041');   // Business and administration, not yet placed more exactly

        $this->assertFalse($this->scopeOutcome(['0412'])->passed, 'being in 041 does not make it Finance');
        $this->assertTrue($this->scopeOutcome(['041'])->passed);
    }

    public function test_any_one_of_several_listed_fields_is_enough(): void
    {
        $this->enrolledIn('0412');

        $this->assertTrue($this->scopeOutcome(['0411', '0412', '0421'])->passed);
    }

    public function test_the_failure_names_what_the_listing_is_open_to(): void
    {
        $this->enrolledIn('0412');

        $outcome = $this->scopeOutcome(['0421']);

        $this->assertFalse($outcome->passed);
        $this->assertFalse($outcome->needsInformation);
        $this->assertStringContainsString('Law', $outcome->message);
    }

    public function test_a_student_with_no_programme_is_asked_for_it_not_failed(): void
    {
        ApplicantProgramme::query()->delete();

        $outcome = $this->scopeOutcome(['0412']);

        $this->assertFalse($outcome->passed);
        $this->assertTrue($outcome->needsInformation, 'missing information must not silently pass or fail');
    }

    public function test_a_programme_under_a_deeper_branch_matches_across_every_broad_area(): void
    {
        foreach ([
            ['0111', '011'], ['0215', '021'], ['0313', '031'], ['0421', '042'], ['0512', '051'], ['0613', '061'],
            ['0715', '071'], ['0811', '081'], ['0913', '091'], ['1013', '101'], ['1041', '104'],
        ] as [$detailed, $narrow]) {
            $this->enrolledIn($detailed);
            $this->assertTrue($this->scopeOutcome([$detailed])->passed, "$detailed exact");
            $this->assertTrue($this->scopeOutcome([$narrow])->passed, "$detailed within $narrow");
            $this->assertTrue($this->scopeOutcome([substr($detailed, 0, 2)])->passed, "$detailed within its broad field");
        }
    }
}
