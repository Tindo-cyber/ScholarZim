<?php

namespace Tests\Feature;

use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use App\Services\ScholarFit\AcademicRecord;
use App\Services\ScholarFit\EligibilityEvaluator;
use App\Support\ApplicationStatus;
use App\Support\Gender;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Gender is required profile information, shown to the provider reviewing an
 * application - and nothing else. The first half of this file proves the
 * provider sees it; the second proves ScholarFit never reads it.
 * Validation and completeness are covered in AcademicProfileTest.
 */
class GenderProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->student = User::where('email', 'student@scholarzim.co.zw')->firstOrFail();
        $this->provider = User::where('email', 'provider@scholarzim.co.zw')->firstOrFail();
    }

    // ------------------------------------------------- provider review page --

    public function test_the_provider_review_page_shows_a_male_applicants_gender(): void
    {
        $this->assertSame(Gender::MALE, $this->profile()->gender);

        $this->assertGenderRow('Male');
    }

    public function test_the_provider_review_page_shows_a_female_applicants_gender(): void
    {
        $this->profile()->forceFill(['gender' => Gender::FEMALE])->save();

        $this->assertGenderRow('Female');
    }

    /** Legacy data: shown as "Not provided", never guessed from the name. */
    public function test_the_provider_review_page_says_not_provided_for_a_legacy_null(): void
    {
        $this->profile()->forceFill(['gender' => null])->save();

        $this->assertGenderRow('Not provided');
    }

    // ------------------------------------------------ not an eligibility rule --

    /** The evaluator and the recommendation service never read the field. */
    public function test_scholarfit_source_never_reads_gender(): void
    {
        foreach ([
            app_path('Services/ScholarFit'),
            app_path('Services/RecommendationService.php'),
        ] as $path) {
            $files = is_dir($path)
                ? array_map(fn ($f) => $f->getPathname(), iterator_to_array(
                    new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
                ))
                : [$path];

            foreach ($files as $file) {
                if (! str_ends_with($file, '.php')) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/gender/i',
                    file_get_contents($file),
                    basename($file) . ' must not read gender'
                );
            }
        }

        $this->assertArrayNotHasKey('gender', (new Opportunity())->getAttributes());
        $this->assertNotContains('gender', (new Opportunity())->getFillable());
    }

    /** The same applicant evaluated as male, female and with no gender gets identical outcomes. */
    public function test_gender_does_not_change_scholarfit_eligibility(): void
    {
        $outcomes = [];

        foreach ([Gender::MALE, Gender::FEMALE, null] as $gender) {
            $profile = $this->profile();
            $profile->forceFill(['gender' => $gender])->save();
            $profile = $profile->fresh();

            $row = [];
            foreach (Opportunity::publiclyVisible()->orderBy('opportunity_id')->get() as $opportunity) {
                $row[$opportunity->opportunity_id] = array_map(
                    fn ($o) => [$o->type, $o->passed, $o->advisory, $o->message],
                    app(EligibilityEvaluator::class)->evaluate($profile, $opportunity, AcademicRecord::fromProfile($profile))
                );
            }

            $outcomes[] = $row;
        }

        $this->assertNotEmpty($outcomes[0]);
        $this->assertSame($outcomes[0], $outcomes[1]);
        $this->assertSame($outcomes[0], $outcomes[2]);

        foreach ($outcomes[0] as $listing) {
            foreach ($listing as [, , , $message]) {
                $this->assertStringNotContainsStringIgnoringCase('gender', $message);
            }
        }
    }

    /** Matches and not-eligible lists - and their order - are the same whatever the gender. */
    public function test_gender_does_not_change_recommendations(): void
    {
        $seen = [];

        foreach ([Gender::MALE, Gender::FEMALE] as $gender) {
            $this->profile()->forceFill(['gender' => $gender])->save();
            $user = $this->student->fresh();
            $service = app(RecommendationService::class);

            $seen[] = [
                array_map(fn ($r) => $r->opportunity->opportunity_id, $service->forUser($user, 0)),
                array_map(fn ($r) => $r->opportunity->opportunity_id, $service->notEligibleForUser($user, 0)),
            ];
        }

        $this->assertNotEmpty($seen[0][0]);
        $this->assertSame($seen[0], $seen[1]);
    }

    // ----------------------------------------------------------------- helpers --

    private function profile(): ApplicantProfile
    {
        return ApplicantProfile::where('user_id', $this->student->user_id)->firstOrFail();
    }

    private function assertGenderRow(string $expected): void
    {
        $opportunity = Opportunity::where('title', 'Zimbabwe Tech Futures Bursary')->firstOrFail();

        $application = Application::updateOrCreate(
            ['user_id' => $this->student->user_id, 'opportunity_id' => $opportunity->opportunity_id],
            ['application_status' => ApplicationStatus::PENDING, 'submitted_at' => Carbon::now()->subDays(2)]
        );

        $html = $this->actingAs($this->provider)
            ->get('/provider/applications/' . $application->application_id)
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<dt[^>]*>\s*Gender\s*<\/dt>\s*<dd[^>]*>\s*' . preg_quote($expected, '/') . '\s*<\/dd>/',
            $html,
            'the provider review page must show a Gender row reading ' . $expected
        );
    }
}
