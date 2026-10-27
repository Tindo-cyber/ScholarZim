<?php

namespace Tests\Feature\Validation;

use App\Exceptions\ProfileIncompleteException;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\ApplicationService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Support\Academic\AcademicCatalogue;
use App\Support\EducationLevel;
use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use App\Support\RoleNames;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The validation that was missing or inconsistent: the application service re-checks what it is handed, one name rule per
 * kind of name, a degree classification only where there is a degree, and "add this to your profile" told apart from "you
 * do not qualify".
 */
class ValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('email', 'tanaka.chirwa@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
        $this->admin = User::where('email', 'admin@scholarzim.co.zw')->firstOrFail();
    }

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function listing(array $overrides = []): Opportunity
    {
        return Opportunity::create(array_merge([
            'provider_user_id' => $this->provider->user_id,
            'provider_name' => 'Validation Provider',
            'title' => 'Validation Bursary ' . uniqid(),
            'description' => 'A listing used to exercise the application service.',
            'education_level' => null,
            'target_field' => null,
            'funding_type' => 'Full Scholarship',
            'country' => 'Zimbabwe',
            'target_country' => 'Zimbabwe',
            'deadline' => Carbon::today()->addDays(30),
            'status' => OpportunityStatus::ACTIVE,
            'moderation_status' => OpportunityModerationStatus::APPROVED,
            'submitted_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now(),
        ], $overrides));
    }

    public function test_submit_rejects_malformed_personal_statement_when_called_directly(): void
    {
        $listing = $this->listing();
        $service = app(ApplicationService::class);
        $before = Application::count();

        foreach ([['personal_statement' => str_repeat('a', 5001)], ['personal_statement' => ['not', 'text']]] as $data) {
            try {
                $service->submit($listing->opportunity_id, $this->student, $data);
                $this->fail('A malformed personal statement was accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('personal_statement', $e->errors());
            }
        }

        $this->assertSame($before, Application::count());
    }

    public function test_submit_rejects_unknown_profile_document_type(): void
    {
        $listing = $this->listing();

        try {
            app(ApplicationService::class)->submit(
                $listing->opportunity_id,
                $this->student,
                ['personal_statement' => null],
                null,
                ['not_a_document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]
            );
            $this->fail('An unknown profile document type was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('documents', $e->errors());
        }

        try {
            app(ApplicationService::class)->submit(
                $listing->opportunity_id,
                $this->student,
                ['personal_statement' => null],
                null,
                ['cv' => UploadedFile::fake()->create('cv.exe', 10, 'application/x-msdownload')]
            );
            $this->fail('A document of a refused type was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('documents.cv', $e->errors());
        }
    }

    public function test_provider_organisation_name_has_letter_via_admin_create(): void
    {
        $payload = [
            'email' => 'new.org@example.com', 'phone' => null, 'role_name' => RoleNames::PROVIDER,
            'password' => 'Str0ngPassword', 'password_confirmation' => 'Str0ngPassword',
        ];

        $this->actingAs($this->admin)->post('/admin/users/create', $payload + ['full_name' => '12345 & 6'])
            ->assertSessionHasErrors('full_name');
        $this->assertDatabaseMissing('users', ['email' => 'new.org@example.com']);

        $this->actingAs($this->admin)->post('/admin/users/create', $payload + ['full_name' => '2 Brothers Education Trust'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new.org@example.com']);
    }

    public function test_degree_classification_prohibited_at_non_tertiary_level(): void
    {
        $classification = AcademicCatalogue::scheme(AcademicCatalogue::TERTIARY)->symbols()[0];

        $this->actingAs($this->student)->post('/applicant/profile', [
            'full_name' => $this->student->full_name,
            'gender' => $this->profile()->gender,
            'date_of_birth' => $this->profile()->date_of_birth?->format('Y-m-d'),
            'education_level' => EducationLevel::O_LEVEL,
            'degree_classification' => $classification,
        ])->assertSessionHasErrors('degree_classification');

        $this->assertNull($this->profile()->fresh()->degree_classification);
    }

    public function test_submit_returns_profile_incomplete_not_unmet_when_only_information_missing(): void
    {
        $this->profile()->forceFill(['education_level' => null, 'degree_classification' => null])->save();
        $listing = $this->listing();

        try {
            app(ApplicationService::class)->submit($listing->opportunity_id, $this->student->refresh(), ['personal_statement' => null]);
            $this->fail('An application from a profile with no education level was accepted.');
        } catch (ProfileIncompleteException $e) {
            $this->assertStringNotContainsString('do not meet', $e->getMessage());
        }
    }

    public function test_requirements_that_cannot_be_checked_are_pending_not_failures(): void
    {
        $this->profile()->forceFill(['education_level' => null])->save();
        $listing = $this->listing(['education_level' => EducationLevel::UNDERGRADUATE]);
        $evaluator = app(EligibilityEvaluator::class);
        $profile = $this->profile()->fresh();
        $record = AcademicRecord::fromProfile($profile);

        $failures = $evaluator->failureMessages($profile, $listing, $record);
        $pending = $evaluator->pendingMessages($profile, $listing, $record);

        $this->assertSame([], $failures, 'a level the profile does not state is not a failed requirement');
        $this->assertNotSame([], $pending);
        $this->assertSame(array_merge($failures, $pending), $evaluator->unmetReasons($profile, $listing, $record), 'unmetReasons() still returns both');

        $exception = ProfileIncompleteException::forPendingRequirements($pending);
        $this->assertStringNotContainsString('do not meet', $exception->getMessage());
        $this->assertStringContainsString($pending[0], $exception->getMessage());
    }
}
