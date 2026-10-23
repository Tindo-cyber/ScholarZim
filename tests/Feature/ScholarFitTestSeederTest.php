<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Models\User;
use App\Services\RecommendationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ScholarFitTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The extra accounts from ScholarFitTestSeeder, and what ScholarFit says about each against each listing.
 *
 * The expectations below are worked out from the rule each listing was built to test (its comment in the seeder),
 * not copied from the engine's output: if ScholarFit changes how it reads points, programmes, institutions,
 * province, locality, age, level or missing information, the cell that depended on it fails here.
 *
 * Uses the full shipped catalogue, because the listings name real programmes and institutions.
 */
class ScholarFitTestSeederTest extends TestCase
{
    use RefreshDatabase;

    private const YES = 'yes';

    private const NO = 'no';

    private const ASK = 'needs information';

    private const OPEN = 'open';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFullCatalogue();
        $this->seed(DatabaseSeeder::class);
        $this->seed(ScholarFitTestSeeder::class);
    }

    private function verdict(string $applicant, string $listing): string
    {
        $user = User::where('email', "fit.$applicant@scholarzim.co.zw")->firstOrFail();
        $fit = app(RecommendationService::class)->evaluateOne($user, Opportunity::where('title', "FIT $listing")->firstOrFail());

        return match (true) {
            $fit->isIneligible() => self::NO,
            $fit->needsInformation() => self::ASK,
            ! $fit->hasStatedRequirements() => self::OPEN,
            default => self::YES,
        };
    }

    public function test_the_seeder_adds_ten_applicants_and_three_providers_and_can_be_run_again(): void
    {
        $count = fn (string $like) => User::where('email', 'like', $like)->count();

        $this->assertSame(10, $count('fit.%') - $count('fit.provider.%'));
        $this->assertSame(3, $count('fit.provider.%'));

        $this->seed(ScholarFitTestSeeder::class);

        $this->assertSame(10, $count('fit.%') - $count('fit.provider.%'), 'a second run resets, it does not duplicate');
        $this->assertSame(1, Opportunity::where('title', 'FIT STEM Excellence Award')->count());
    }

    public function test_the_seeder_will_not_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('will not run in production');

        (new ScholarFitTestSeeder())->run();
    }

    public function test_a_listing_that_asks_nothing_is_open_to_every_applicant_even_one_with_a_thin_profile(): void
    {
        foreach (['rudo.mutasa', 'simba.dube', 'nyasha.hove', 'tawanda.zhou', 'memory.chikwanha', 'lloyd.ncube', 'anesu.mlambo', 'tariro.gumbo', 'fadzai.moyo', 'joseph.banda'] as $who) {
            $this->assertSame(self::OPEN, $this->verdict($who, 'Open Doors Bursary'), $who);
        }
    }

    public function test_points_and_a_subject_grade(): void
    {
        $this->assertSame(self::YES, $this->verdict('rudo.mutasa', 'STEM Excellence Award'), '14 points, Maths A');
        $this->assertSame(self::YES, $this->verdict('nyasha.hove', 'STEM Excellence Award'), '15 points');
        $this->assertSame(self::YES, $this->verdict('joseph.banda', 'STEM Excellence Award'), '13 points, Maths A');
        $this->assertSame(self::NO, $this->verdict('simba.dube', 'STEM Excellence Award'), '4 points, Maths D');
        $this->assertSame(self::NO, $this->verdict('fadzai.moyo', 'STEM Excellence Award'), '11 points is one short of 12');
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'STEM Excellence Award'), 'no results recorded: ask, do not refuse');
    }

    public function test_programme_scope_and_field_scope(): void
    {
        $this->assertSame(self::YES, $this->verdict('rudo.mutasa', 'Computing Programmes Bursary'));
        $this->assertSame(self::YES, $this->verdict('fadzai.moyo', 'Computing Programmes Bursary'));
        // School-leavers who chose other programmes: outside their choices (a note and a later place), not refused.
        $this->assertSame(self::YES, $this->verdict('nyasha.hove', 'Computing Programmes Bursary'));
        $this->assertSame(self::YES, $this->verdict('simba.dube', 'Computing Programmes Bursary'));
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'Computing Programmes Bursary'), 'no programme chosen yet');

        $this->assertSame(self::YES, $this->verdict('nyasha.hove', 'Health Sciences Scholarship'));
        $this->assertSame(self::YES, $this->verdict('rudo.mutasa', 'Health Sciences Scholarship'), 'a leaver who chose Computing: outside their choices, not refused');
        $this->assertSame(self::YES, $this->verdict('joseph.banda', 'Health Sciences Scholarship'));
    }

    public function test_institution_scope(): void
    {
        $this->assertSame(self::YES, $this->verdict('fadzai.moyo', 'Midlands State University Bursary'), 'applied to MSU');
        $this->assertSame(self::NO, $this->verdict('rudo.mutasa', 'Midlands State University Bursary'), 'applied to UZ');
        $this->assertSame(self::ASK, $this->verdict('simba.dube', 'Midlands State University Bursary'), 'has not said where they applied');
    }

    public function test_province_settlement_and_locality(): void
    {
        $this->assertSame(self::YES, $this->verdict('simba.dube', 'Manicaland Rural Learners Fund'));
        $this->assertSame(self::NO, $this->verdict('fadzai.moyo', 'Manicaland Rural Learners Fund'), 'Midlands, not Manicaland');
        $this->assertSame(self::NO, $this->verdict('rudo.mutasa', 'Manicaland Rural Learners Fund'));
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'Manicaland Rural Learners Fund'), 'no province recorded');

        $this->assertSame(self::YES, $this->verdict('fadzai.moyo', 'Gweru Community Scholarship'));
        $this->assertSame(self::NO, $this->verdict('rudo.mutasa', 'Gweru Community Scholarship'));
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'Gweru Community Scholarship'));
    }

    public function test_age_limits(): void
    {
        foreach (['rudo.mutasa', 'nyasha.hove', 'fadzai.moyo', 'joseph.banda', 'simba.dube'] as $who) {
            $this->assertSame(self::YES, $this->verdict($who, 'Young Innovators Under-22 Grant'), $who);
        }

        $this->assertSame(self::NO, $this->verdict('tawanda.zhou', 'Young Innovators Under-22 Grant'), 'aged 24 (and past the level)');
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'Young Innovators Under-22 Grant'), 'no date of birth');
        $this->assertSame(self::NO, $this->verdict('lloyd.ncube', 'Postgraduate Computing Fellowship'), 'aged 31 against a limit of 30');
    }

    public function test_the_education_ladder(): void
    {
        $this->assertSame(self::YES, $this->verdict('tawanda.zhou', 'Postgraduate Computing Fellowship'), 'degree finished, Masters is next');
        $this->assertSame(self::NO, $this->verdict('rudo.mutasa', 'Postgraduate Computing Fellowship'), 'A-Level is below Masters');

        $this->assertSame(self::YES, $this->verdict('memory.chikwanha', 'Bachelor Completion Bursary'), 'holds the Diploma the floor asks for');
        $this->assertSame(self::NO, $this->verdict('rudo.mutasa', 'Bachelor Completion Bursary'), 'no Diploma');

        $this->assertSame(self::OPEN, $this->verdict('lloyd.ncube', 'Doctoral Research Grant'), 'Masters finished, PhD is next');
        $this->assertSame(self::NO, $this->verdict('tawanda.zhou', 'Doctoral Research Grant'), 'a degree is two steps short');

        $this->assertSame(self::NO, $this->verdict('memory.chikwanha', 'Diploma Skills Award'), 'already holds a Diploma');
        $this->assertSame(self::OPEN, $this->verdict('anesu.mlambo', 'Diploma Skills Award'), 'O-Level leaver choosing a Diploma programme');
    }

    public function test_a_missing_document_is_a_question_and_a_held_one_is_met(): void
    {
        $this->assertSame(self::ASK, $this->verdict('tariro.gumbo', 'Proof Of Results Scholarship'));
        $this->assertSame(self::YES, $this->verdict('rudo.mutasa', 'Proof Of Results Scholarship'), 'results certificate on file');
    }

    public function test_listings_that_are_expired_or_unapproved_are_matched_to_nobody(): void
    {
        $service = app(RecommendationService::class);

        foreach (User::where('email', 'like', 'fit.%')->where('email', 'not like', 'fit.provider.%')->get() as $user) {
            $titles = array_map(fn ($r) => $r->opportunity->title, $service->forUser($user, 0));

            $this->assertNotContains('FIT Expired Merit Award', $titles, $user->full_name);
            $this->assertNotContains('FIT Awaiting Review Award', $titles, $user->full_name);
        }
    }

    public function test_the_applicants_the_seeder_calls_complete_are_and_the_thin_one_is_not(): void
    {
        foreach (['rudo.mutasa', 'simba.dube', 'nyasha.hove', 'tawanda.zhou', 'memory.chikwanha', 'lloyd.ncube', 'anesu.mlambo', 'fadzai.moyo', 'joseph.banda'] as $who) {
            $user = User::where('email', "fit.$who@scholarzim.co.zw")->firstOrFail();
            $this->assertTrue($user->applicantProfile->isComplete(), $who . ' is missing ' . implode(', ', $user->applicantProfile->missingFields()));
        }

        $this->assertFalse(User::where('email', 'fit.tariro.gumbo@scholarzim.co.zw')->firstOrFail()->applicantProfile->isComplete());
    }

    public function test_the_providers_websites_reach_their_listings(): void
    {
        $listing = Opportunity::where('title', 'FIT STEM Excellence Award')->firstOrFail();

        $this->get(route('scholarships.show', $listing->opportunity_id))
            ->assertOk()
            ->assertSee('https://zambezi-stem.example.org')
            ->assertSee('confirmed by ScholarZim');

        $open = Opportunity::where('title', 'FIT Open Doors Bursary')->firstOrFail();
        $this->get(route('scholarships.show', $open->opportunity_id))->assertOk()->assertDontSee('Visit organisation website');
    }
}
