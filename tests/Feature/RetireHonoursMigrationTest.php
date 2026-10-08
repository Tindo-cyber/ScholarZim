<?php

namespace Tests\Feature;

use App\Models\Opportunity;
use App\Services\ListingRiskChecker;
use App\Services\ScholarFit\DescriptionEligibility;
use App\Support\EducationLevel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Honours" stops being a level: existing rows move to Undergraduate, listings are flagged
 * for confirmation, and down() puts back exactly what was there.
 */
class RetireHonoursMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->migration = require database_path('migrations/2025_01_01_000012_retire_honours_education_level.php');
    }

    /** Put the schema back as it was before the migration, so up() can be run against legacy data. */
    private function beforeMigration(): void
    {
        $this->migration->down();
        $this->assertFalse(Schema::hasTable('legacy_honours_levels'));
    }

    public function test_profiles_move_to_undergraduate_and_down_restores_the_original_spelling(): void
    {
        $this->beforeMigration();

        $ids = DB::table('applicant_profiles')->orderBy('profile_id')->limit(3)->pluck('profile_id')->all();
        DB::table('applicant_profiles')->where('profile_id', $ids[0])->update(['education_level' => 'HONOURS']);
        DB::table('applicant_profiles')->where('profile_id', $ids[1])->update(['education_level' => 'Honours Degree']);
        $untouched = DB::table('applicant_profiles')->where('profile_id', $ids[2])->value('education_level');

        $this->migration->up();

        $this->assertSame('UNDERGRADUATE', DB::table('applicant_profiles')->where('profile_id', $ids[0])->value('education_level'));
        $this->assertSame('UNDERGRADUATE', DB::table('applicant_profiles')->where('profile_id', $ids[1])->value('education_level'));
        $this->assertSame($untouched, DB::table('applicant_profiles')->where('profile_id', $ids[2])->value('education_level'));

        $this->migration->down();

        $this->assertSame('HONOURS', DB::table('applicant_profiles')->where('profile_id', $ids[0])->value('education_level'));
        $this->assertSame('Honours Degree', DB::table('applicant_profiles')->where('profile_id', $ids[1])->value('education_level'));
        $this->assertSame($untouched, DB::table('applicant_profiles')->where('profile_id', $ids[2])->value('education_level'));
    }

    public function test_listings_move_and_are_flagged_for_confirmation_and_down_clears_only_that_flag(): void
    {
        $this->beforeMigration();

        [$target, $minimum, $other] = DB::table('opportunities')->orderBy('opportunity_id')->limit(3)->pluck('opportunity_id')->all();

        $existing = [['code' => 'phone_only_contact', 'message' => 'kept']];
        DB::table('opportunities')->where('opportunity_id', $target)->update(['education_level' => 'HONOURS', 'risk_flags' => json_encode($existing)]);
        DB::table('opportunities')->where('opportunity_id', $minimum)->update(['minimum_education_level' => 'Honours']);
        $otherBefore = DB::table('opportunities')->where('opportunity_id', $other)->first();

        $this->migration->up();

        $flagged = Opportunity::find($target);
        $this->assertSame('UNDERGRADUATE', $flagged->education_level);
        $this->assertSame(
            ['phone_only_contact', ListingRiskChecker::LEVEL_TO_CONFIRM],
            array_column($flagged->risk_flags, 'code'),
            'the new flag is added beside the existing ones'
        );
        $this->assertStringContainsString('Postgraduate', $flagged->risk_flags[1]['message']);

        $moved = Opportunity::find($minimum);
        $this->assertSame('UNDERGRADUATE', $moved->minimum_education_level);
        $this->assertSame([ListingRiskChecker::LEVEL_TO_CONFIRM], array_column($moved->risk_flags, 'code'));

        $this->assertEquals($otherBefore, DB::table('opportunities')->where('opportunity_id', $other)->first(), 'a listing that never said Honours is untouched');

        $this->migration->down();

        $restored = Opportunity::find($target);
        $this->assertSame('HONOURS', $restored->education_level);
        $this->assertSame($existing, $restored->risk_flags, 'only the migration\'s own flag is removed');
        $this->assertSame('Honours', Opportunity::find($minimum)->minimum_education_level);
        $this->assertNull(Opportunity::find($minimum)->risk_flags);
    }

    public function test_the_migration_can_run_again_after_a_rollback(): void
    {
        $this->beforeMigration();
        DB::table('applicant_profiles')->orderBy('profile_id')->limit(1)->update(['education_level' => 'HONOURS']);

        $this->migration->up();
        $this->migration->down();
        $this->migration->up();

        $this->assertSame(0, DB::table('applicant_profiles')->whereRaw("LOWER(education_level) = 'honours'")->count());
    }

    public function test_the_words_bsc_honours_in_a_title_are_read_as_an_undergraduate_award(): void
    {
        $conditions = DescriptionEligibility::conditions('BSc Honours Bursary', 'Open to all.');

        $this->assertContains(
            EducationLevel::UNDERGRADUATE,
            array_map(fn ($c) => $c->value, $conditions)
        );
    }

    public function test_graduating_with_honours_is_not_mistaken_for_a_level(): void
    {
        $conditions = DescriptionEligibility::conditions('Research Award', 'Applicants who graduated with honours are encouraged.');

        $this->assertSame([], $conditions);
    }
}
