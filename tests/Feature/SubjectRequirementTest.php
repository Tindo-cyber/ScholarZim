<?php

namespace Tests\Feature;

use App\Models\AcademicQualification;
use App\Models\AcademicSubject;
use App\Models\AcademicResult;
use App\Models\Opportunity;
use App\Models\OpportunitySubjectRequirement;
use App\Models\User;
use App\Services\RecommendationService;
use App\Support\OpportunityStatus;
use App\Support\OpportunityModerationStatus;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Subject-level eligibility: scholarships that require specific subjects,
 * and students who record their results subject by subject.
 */
class SubjectRequirementTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;

    private User $student;

    private AcademicQualification $qual;

    private AcademicSubject $mathSubject;

    private AcademicSubject $physicsSubject;

    private AcademicSubject $chemistrySubject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();

        $this->qual = AcademicQualification::where('qualification_key', 'zimsec-a-level')->firstOrFail();

        $this->mathSubject = AcademicSubject::where('qualification_id', $this->qual->id)
            ->where('name', 'Mathematics')->firstOrFail();
        $this->physicsSubject = AcademicSubject::where('qualification_id', $this->qual->id)
            ->where('name', 'Physics')->firstOrFail();
        $this->chemistrySubject = AcademicSubject::where('qualification_id', $this->qual->id)
            ->where('name', 'Chemistry')->firstOrFail();
    }

    public function test_a_provider_can_attach_subject_requirements_to_a_listing(): void
    {
        $opportunity = Opportunity::create([
            'provider_user_id' => $this->provider->user_id,
            'title' => 'Engineering Bursary',
            'description' => 'Requires Mathematics and a science.',
            'education_level' => 'A_LEVEL',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->physicsSubject->id,
            'minimum_grade' => 'C',
        ]);

        $opportunity->load('subjectRequirements.subject', 'subjectRequirements.qualification');

        $this->assertCount(2, $opportunity->subjectRequirements);
        $this->assertTrue($opportunity->hasEligibilityRules());
    }

    public function test_the_detail_page_lists_required_subjects(): void
    {
        $opportunity = Opportunity::create([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Test Provider',
            'title' => 'Subject-Gated Scholarship',
            'description' => 'Requires Mathematics.',
            'education_level' => 'UNDERGRADUATE',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        $this->actingAs($this->student)
            ->get('/scholarships/' . $opportunity->opportunity_id)
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('minimum C');
    }

    public function test_a_student_with_matching_results_is_eligible(): void
    {
        $opportunity = $this->approvedOpportunity('Math Required');

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        $this->giveStudentResults([
            ['subject' => $this->mathSubject, 'grade' => 'A'],
        ]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_student_missing_the_required_subject_is_excluded(): void
    {
        $opportunity = $this->approvedOpportunity('Math Required');

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        $this->giveStudentResults([
            ['subject' => $this->physicsSubject, 'grade' => 'A'],
        ]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_student_with_a_grade_below_minimum_is_excluded(): void
    {
        $opportunity = $this->approvedOpportunity('Math C Required');

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        $this->giveStudentResults([
            ['subject' => $this->mathSubject, 'grade' => 'D'],
        ]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_requirement_with_no_grade_is_met_by_any_result(): void
    {
        $opportunity = $this->approvedOpportunity('Math Required, No Grade');

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => null,
        ]);

        $this->giveStudentResults([
            ['subject' => $this->mathSubject, 'grade' => 'E'],
        ]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_multiple_requirements_all_must_be_met(): void
    {
        $opportunity = $this->approvedOpportunity('Two Subjects Required');

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->mathSubject->id,
            'minimum_grade' => 'C',
        ]);

        OpportunitySubjectRequirement::create([
            'opportunity_id' => $opportunity->opportunity_id,
            'qualification_id' => $this->qual->id,
            'subject_id' => $this->physicsSubject->id,
            'minimum_grade' => 'C',
        ]);

        // Has Mathematics at A but no Physics — should fail.
        $this->giveStudentResults([
            ['subject' => $this->mathSubject, 'grade' => 'A'],
        ]);

        $this->assertNotContains($opportunity->opportunity_id, $this->rankedIds());

        // Now add Physics at C — should pass.
        $this->giveStudentResults([
            ['subject' => $this->mathSubject, 'grade' => 'A'],
            ['subject' => $this->physicsSubject, 'grade' => 'C'],
        ]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    public function test_a_listing_with_no_subject_requirements_is_unaffected(): void
    {
        $opportunity = $this->approvedOpportunity('No Subject Requirement');

        $this->giveStudentResults([
            ['subject' => $this->physicsSubject, 'grade' => 'B'],
        ]);

        $this->assertContains($opportunity->opportunity_id, $this->rankedIds());
    }

    // --------------------------------------------------------------- helpers --

        private function approvedOpportunity(string $title): Opportunity
        {
            return Opportunity::create([
                'provider_user_id' => $this->provider->user_id,
                'provider_name' => 'Test Provider',
                'title' => $title,
                'description' => 'Test listing with subject requirements.',
                'education_level' => 'UNDERGRADUATE',
            'target_field' => 'Engineering',
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'required_province' => null,
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<int, array{subject: AcademicSubject, grade: string}>  $rows
     */
    private function giveStudentResults(array $rows): void
    {
        $profile = $this->student->applicantProfile;

        // updateOrCreate rather than create: academic_results is uniquely keyed
        // on (profile_id, qualification_id, subject_id), so recording the same
        // subject again replaces the grade rather than adding a second row.
        // This helper is called twice in one test below, and before the key
        // existed that quietly left two Mathematics rows behind - both of which
        // any points total would have counted.
        foreach ($rows as $row) {
            AcademicResult::updateOrCreate(
                [
                    'profile_id' => $profile->profile_id,
                    'qualification_id' => $this->qual->id,
                    'subject_id' => $row['subject']->id,
                ],
                [
                    'result' => $row['grade'],
                    'derived_points' => $this->qual->pointsFor($row['grade']),
                ]
            );
        }
    }

    /** @return array<int, int> */
    private function rankedIds(): array
    {
        $this->student->refresh();

        return array_map(
            static fn ($scored) => (int) $scored->opportunity->opportunity_id,
            app(RecommendationService::class)->forUser($this->student, 0)
        );
    }
}
